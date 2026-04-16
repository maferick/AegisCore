<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domains\Markets\Models\MarketHub;
use App\Domains\Markets\Models\MarketHubCollector;
use App\Domains\Markets\Services\MarketHubAccessPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Livewire component powering `/account/market-hubs`.
 *
 * Purpose (ADR-0005 § Follow-ups #1): the richer multi-hub list view
 * and the set-default preference UI. Registration / revoke stay on
 * `/account/settings` — this page only adds what that one can't
 * cleanly express alongside the structure picker:
 *
 *   - Every hub the user may view via the intersection rule, grouped
 *     by public-reference vs private. Same source of truth as the
 *     market pages (`MarketHubAccessPolicy::visibleHubsFor`), so the
 *     list here is guaranteed to match what comparison dropdowns
 *     elsewhere will show.
 *   - For private hubs: collector count, primary indicator, last
 *     sync / last access verified, freeze state
 *     (`disabled_reason = 'no_active_collector'`) — the operational
 *     signals a donor needs to see when something is off.
 *   - Set / clear `users.default_private_market_hub_id`. Null-safe
 *     per ADR-0005 § User preference: a later entitlement revocation
 *     silently demotes the UI to "no default" rather than breaking.
 *
 * What this page intentionally does NOT do:
 *
 *   - Register new hubs — that lives on `/account/settings` because
 *     it needs the ESI-backed structure picker + a fresh market
 *     token. Duplicating that flow here would fork the trust-checks
 *     in ADR-0005's Registration flow.
 *   - Revoke a collector — same reason; the settings page owns the
 *     "your structures" interactive surface.
 *   - Grant entitlements to other users — phase-2 group-sharing UX
 *     (corp / alliance) is a separate ADR + Filament resource.
 *
 * Security: every list read goes through MarketHubAccessPolicy. Set-
 * default re-checks `canView()` before writing — a forged POST with
 * a hub id the user can't see is rejected regardless of whether it
 * existed in the rendered list. Public-reference hubs (Jita) can't
 * be set as the default: the pin is scoped to *private* hubs per
 * ADR-0005 § User preference ("the donor's pinned comparison
 * target").
 */
class AccountMarketHubs extends Component
{
    public ?string $status = null;

    public ?string $error = null;

    public function render(MarketHubAccessPolicy $policy): View
    {
        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        $hubs = $policy->visibleHubsFor($user)
            ->with([
                'region:id,name',
                'collectors:id,hub_id,user_id,character_id,is_primary,is_active,consecutive_failure_count,last_success_at,last_failure_at,failure_reason',
            ])
            ->orderByDesc('is_public_reference')
            ->orderBy('structure_name')
            ->get();

        return view('livewire.account.market-hubs', [
            'user' => $user,
            'has_feature_access' => $policy->hasFeatureAccess($user),
            'public_hubs' => $hubs->where('is_public_reference', true)->values(),
            'private_hubs' => $this->decoratePrivateHubs(
                $hubs->where('is_public_reference', false)->values(),
                $user->id,
            ),
            'default_hub_id' => $user->default_private_market_hub_id,
        ]);
    }

    /**
     * Attach per-user decoration so the view can render without
     * re-querying: which collector row (if any) belongs to *this*
     * donor, and the live hub-level freeze state.
     *
     * @param  Collection<int, MarketHub>  $hubs
     * @return Collection<int, MarketHub>
     */
    private function decoratePrivateHubs(Collection $hubs, int $userId): Collection
    {
        return $hubs->each(function (MarketHub $hub) use ($userId): void {
            /** @var Collection<int, MarketHubCollector> $collectors */
            $collectors = $hub->collectors;
            $hub->setAttribute(
                'my_collector',
                $collectors->firstWhere('user_id', $userId),
            );
            $hub->setAttribute(
                'active_collector_count',
                $collectors->where('is_active', true)->count(),
            );
            $hub->setAttribute(
                'is_frozen',
                $hub->disabled_reason === 'no_active_collector',
            );
        });
    }

    /**
     * Pin a private hub as the user's default comparison target.
     * Re-checks the intersection rule at write time — a forged id
     * that wasn't in the rendered list is rejected.
     *
     * Public-reference hubs are intentionally not valid defaults
     * (ADR-0005 § User preference: the default is the "pinned
     * private hub" a comparison panel defaults to — Jita is the
     * implicit other half).
     */
    public function setDefault(int $hubId, MarketHubAccessPolicy $policy): void
    {
        $this->status = null;
        $this->error = null;

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        $hub = MarketHub::query()->find($hubId);
        if ($hub === null || ! $policy->canView($user, $hub)) {
            $this->error = 'Hub not found.';

            return;
        }

        if ($hub->isPublicReference()) {
            $this->error = 'Public-reference hubs (like Jita) are always shown alongside — pick a private hub as your default.';

            return;
        }

        $user->default_private_market_hub_id = $hub->id;
        $user->save();

        $this->status = 'Default hub set to '.($hub->structure_name ?? "#{$hub->location_id}").'.';
    }

    /**
     * Clear the user's pinned default. The UI reverts to "no default"
     * and any comparison panel falls back to whatever inference rule
     * applies (first entitled hub, etc.).
     */
    public function clearDefault(): void
    {
        $this->status = null;
        $this->error = null;

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        if ($user->default_private_market_hub_id === null) {
            return;
        }

        $user->default_private_market_hub_id = null;
        $user->save();

        $this->status = 'Default hub cleared.';
    }
}
