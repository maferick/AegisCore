<?php

declare(strict_types=1);

namespace App\Domains\Markets\Services;

use App\Domains\Markets\Models\MarketHub;
use App\Domains\Markets\Models\MarketHubEntitlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Single chokepoint for "can this user see this market hub?"
 *
 * ADR-0005 § Intersection rule:
 *
 *     can_view_private_hub(user, hub) = has_feature_access(user)
 *                                    AND has_hub_access(user, hub)
 *
 * where:
 *
 *   - has_feature_access(user) := user.isDonor() || user.isAdmin()
 *   - has_hub_access(user, hub) := exists a market_hub_entitlements
 *     row whose (subject_type, subject_id) matches the user, OR
 *     one of the user's corp / alliance IDs (phase-2 group sharing).
 *
 * Public-reference hubs (is_public_reference = true — Jita and any
 * other NPC hubs the platform surfaces as baseline) short-circuit:
 * visible to everyone regardless of donor / entitlement state.
 *
 * This service is the ONLY place that implements the intersection
 * rule. Every market page, Livewire component, Filament resource,
 * and API endpoint that exposes hub-scoped data is expected to route
 * through `canView()` / `visibleHubsFor()` — do not reinvent the
 * check at the call site. A single chokepoint is how RBAC stays
 * enforceable when the feature grows (corp sharing, per-hub
 * feature flags, premium tiers).
 *
 * Admin bypass: an admin user passes `has_feature_access` by virtue
 * of isAdmin() === true regardless of donor status, but still has
 * to satisfy `has_hub_access`. Admins are not implicitly entitled to
 * every private hub — the audit trail matters. The Filament admin
 * resource can grant themselves an entitlement when they need to
 * access one for support / moderation purposes, which leaves a
 * paper trail in `granted_by_user_id` + `granted_at`.
 */
final class MarketHubAccessPolicy
{
    /**
     * True if this user may view this specific hub, right now.
     *
     * Evaluation order is deliberately (a) public-reference check
     * (cheap, no DB round-trip), (b) feature access (one query, and
     * often cached on the user object by the caller), (c)
     * entitlement lookup (one query scoped to the hub). Cheapest
     * guard first so a free user viewing Jita is a trivial boolean
     * return.
     */
    public function canView(User $user, MarketHub $hub): bool
    {
        if ($hub->isPublicReference()) {
            return true;
        }

        if (! $hub->is_active) {
            return false;
        }

        if (! $this->hasFeatureAccess($user)) {
            return false;
        }

        return $this->hasHubAccess($user, $hub);
    }

    /**
     * Query builder scoped to hubs this user may see. Use in place
     * of MarketHub::query() anywhere the UI enumerates hubs.
     *
     *     MarketHub::query()->... // raw, DO NOT USE from UI / API
     *     app(MarketHubAccessPolicy::class)->visibleHubsFor($user)...
     *
     * Returns a query (not a collection) so callers can further
     * constrain / paginate without loading the full set into memory.
     *
     * The rule implemented here MUST stay in lockstep with canView():
     * both are ADR-0005 § Intersection rule. If they diverge, a page
     * that calls visibleHubsFor() will list a hub that canView()
     * then refuses to let the user open — a confusing UX bug.
     */
    public function visibleHubsFor(User $user): Builder
    {
        $query = MarketHub::query()->where('is_active', true);

        if (! $this->hasFeatureAccess($user)) {
            // No feature access → only the public reference set.
            return $query->where('is_public_reference', true);
        }

        // Has feature access → public reference set + everything
        // this user is explicitly entitled to view.
        $userId = (int) $user->id;

        return $query->where(function (Builder $inner) use ($userId): void {
            $inner->where('is_public_reference', true)
                ->orWhereIn('id', $this->entitledHubIds($userId));
        });
    }

    /**
     * True iff the user currently satisfies the donor-or-admin
     * feature gate. Does NOT consider any specific hub.
     */
    public function hasFeatureAccess(User $user): bool
    {
        return $user->isDonor() || $user->isAdmin();
    }

    /**
     * True iff an entitlement row exists matching the user on this
     * hub. Does NOT consider the feature gate — callers wanting the
     * full intersection rule should use canView().
     *
     * Phase-1 scope: only subject_type = 'user' is matched. The
     * schema accepts 'corp' / 'alliance' rows (pre-wired for
     * phase-2 group sharing), but matching them requires a
     * character → corp / alliance resolver that is not wired yet.
     * If the policy sees such rows it logs a warning once per
     * call — intended to flag a partially-rolled-out state during
     * phase-2 deployment, not a quiet data-loss path.
     */
    public function hasHubAccess(User $user, MarketHub $hub): bool
    {
        if ($hub->isPublicReference()) {
            return true;
        }

        $matched = MarketHubEntitlement::query()
            ->where('hub_id', $hub->id)
            ->forUser((int) $user->id)
            ->exists();

        if ($matched) {
            return true;
        }

        // Warn if the hub has corp/alliance entitlements the v1
        // policy can't evaluate — helps operators notice when they
        // deploy phase-2 grants before the phase-2 resolver.
        $hasGroupGrants = MarketHubEntitlement::query()
            ->where('hub_id', $hub->id)
            ->whereIn('subject_type', [
                MarketHubEntitlement::SUBJECT_TYPE_CORP,
                MarketHubEntitlement::SUBJECT_TYPE_ALLIANCE,
            ])
            ->exists();

        if ($hasGroupGrants) {
            Log::warning('market_hub_entitlements: corp/alliance grants present but group resolution is not wired (v1)', [
                'hub_id' => $hub->id,
                'user_id' => $user->id,
            ]);
        }

        return false;
    }

    /**
     * IDs of every hub this user is directly entitled to view.
     * Expanded in phase-2 to union in corp / alliance grants once
     * the user → corp / alliance resolver lands.
     *
     * @return Builder
     */
    private function entitledHubIds(int $userId): Builder
    {
        return MarketHubEntitlement::query()
            ->select('hub_id')
            ->forUser($userId);
    }
}
