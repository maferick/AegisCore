<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domains\Markets\Models\MarketWatchedLocation;
use App\Domains\Markets\Services\StructurePickerService;
use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Services\Eve\MarketTokenAuthorizer;
use App\Services\Eve\Sso\EveSsoClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use RuntimeException;

/**
 * Livewire component powering `/account/settings`.
 *
 * Three surfaces on one page:
 *
 *   1. Identity card (static — read-only from the authed user).
 *   2. Market-data authorisation (donor-gated) — CTA, token status.
 *   3. Watched structures (donor-gated) — interactive: search for
 *      structures the donor's character has ACL-visible access to,
 *      add one to `market_watched_locations`, remove an existing
 *      one.
 *
 * The search uses the donor's OWN token via
 * `StructurePickerService` → ESI `/characters/{id}/search/`. ESI
 * returns only IDs the character can see, so there's no path by
 * which the picker surfaces structures the donor doesn't have
 * access to. The system never accepts free-form structure IDs for
 * donors.
 *
 * Add flow:
 *
 *   1. Operator types query (3+ chars).
 *   2. `search()` hits ESI via donor's token, populates `$results`.
 *   3. Operator clicks "Watch" on a result → `addStructure($id)`.
 *   4. We re-validate the ID was in THIS request's search results
 *      (server-side — not just client-side UI), then upsert a row
 *      in `market_watched_locations` with `owner_user_id = auth()->id()`.
 *
 * Remove flow: `removeStructure($rowId)` deletes one of the
 * authed user's own watched rows. We scope by `owner_user_id` at
 * the query level so a forged POST can't delete another donor's
 * rows.
 */
class AccountSettings extends Component
{
    /** Free-text search the donor has typed for the picker. */
    public string $query = '';

    /**
     * Structure candidates from the most recent search. Each entry:
     * ['structure_id' => int, 'name' => string, 'region_id' => int,
     *  'solar_system_id' => int, 'system_name' => string].
     *
     * @var list<array<string, mixed>>
     */
    public array $results = [];

    /** Flash-ish status message for the last action (UI feedback). */
    public ?string $status = null;

    public ?string $error = null;

    /**
     * Structure IDs surfaced in the current `$results` list. Used
     * server-side to validate that `addStructure($id)` is operating
     * on an ID that THIS session's search just resolved, not a
     * free-form paste. Cleared whenever `$results` is overwritten.
     *
     * @var list<int>
     */
    public array $resultStructureIds = [];

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public function render(): View
    {
        return view('livewire.account.settings', [
            'user' => Auth::user(),
            'is_donor' => $this->isDonor(),
            'is_admin' => $this->isAdmin(),
            // Feature gate for the market-data + structure-picker
            // sections. Admins are allowed through as operators
            // regardless of donor status — they run the platform
            // and need to be able to add locations for testing,
            // support, or moderation. Donors pay for the same access.
            'has_market_access' => $this->hasMarketAccess(),
            'sso_configured' => EveSsoClient::isConfigured(),
            'market_redirect_url' => EveSsoClient::isConfigured()
                ? route('auth.eve.market.redirect')
                : null,
            'market_token' => $this->marketToken(),
            'watched_structures' => $this->watchedStructures(),
        ]);
    }

    // ------------------------------------------------------------------
    // Computed helpers — per-render, no caching needed at this size.
    // ------------------------------------------------------------------

    private function isDonor(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->isDonor();
    }

    private function isAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->isAdmin();
    }

    /**
     * Intersection-free feature gate: admin OR donor. Matches the
     * "operators + paying customers get the premium surface" rule
     * the market-hub policy uses for visibility. A user who loses
     * both (donor expiry + admin revocation) immediately stops
     * seeing the picker on their next page render — there's no
     * cached flag.
     */
    private function hasMarketAccess(): bool
    {
        return $this->isDonor() || $this->isAdmin();
    }

    private function marketToken(): ?EveMarketToken
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        return EveMarketToken::query()
            ->where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->first();
    }

    /** @return Collection<int, MarketWatchedLocation> */
    private function watchedStructures(): Collection
    {
        $user = Auth::user();
        if ($user === null) {
            return collect();
        }

        return MarketWatchedLocation::query()
            ->where('owner_user_id', $user->id)
            ->orderBy('name')
            ->get();
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * Run the ESI-backed search and populate `$results`. The query
     * is validated to be >= 3 chars (ESI minimum for the search
     * endpoint).
     */
    public function search(StructurePickerService $picker, MarketTokenAuthorizer $authorizer): void
    {
        $this->error = null;
        $this->status = null;
        $this->results = [];
        $this->resultStructureIds = [];

        if (strlen(trim($this->query)) < 3) {
            $this->error = 'Type at least 3 characters to search.';

            return;
        }

        $token = $this->marketToken();
        if ($token === null) {
            $this->error = 'Authorise market data first — use the button above.';

            return;
        }

        try {
            // The picker is token-agnostic now; resolve a fresh access
            // token for this donor's EveMarketToken row via the
            // authorizer (row-locked refresh), then hand raw
            // (character_id, access_token) to the picker.
            $accessToken = $authorizer->freshAccessToken($token);
            $this->results = $picker->search($token->character_id, $accessToken, $this->query);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->resultStructureIds = array_map(
            static fn ($r) => (int) $r['structure_id'],
            $this->results,
        );
        if ($this->results === []) {
            $this->status = 'No matching structures — your character may not have access to any that match this name.';
        }
    }

    /**
     * Add a structure to the donor's watched list. `$structureId`
     * MUST be one of the IDs we just surfaced in `$results` — this
     * enforces the "never accept free-form IDs" invariant at the
     * server side (not just the client).
     */
    public function addStructure(int $structureId): void
    {
        $this->error = null;
        $this->status = null;

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }
        if (! ($user->isDonor() || $user->isAdmin())) {
            // Admins bypass the donor gate as operators (ADR-0005
            // intersection rule applied to /account/settings).
            $this->error = 'Market data access is a donor benefit.';

            return;
        }

        if (! in_array($structureId, $this->resultStructureIds, true)) {
            // Either the session's search results expired (page sat
            // idle), or someone is POSTing a forged structure_id.
            // Either way, refuse. Point the operator back at the
            // search flow — they're not locked out, just asked to
            // re-search.
            $this->error = 'Re-run the search before adding. The server only accepts structures from the latest search results.';

            return;
        }

        // Look up the resolved candidate from `$results` — we already
        // have its name + region_id from the search flow. This saves
        // an extra ESI round-trip on add.
        $candidate = null;
        foreach ($this->results as $r) {
            if ((int) $r['structure_id'] === $structureId) {
                $candidate = $r;
                break;
            }
        }
        if ($candidate === null) {
            // Belt-and-braces — in_array above should have already
            // caught this.
            $this->error = 'Candidate not found in current results.';

            return;
        }

        DB::transaction(function () use ($user, $structureId, $candidate): void {
            MarketWatchedLocation::query()->updateOrCreate(
                [
                    'owner_user_id' => $user->id,
                    'location_id' => $structureId,
                ],
                [
                    'location_type' => MarketWatchedLocation::LOCATION_TYPE_PLAYER_STRUCTURE,
                    'region_id' => (int) $candidate['region_id'],
                    'name' => (string) $candidate['name'],
                    'enabled' => true,
                    // Reset failure bookkeeping if this is a re-add of a
                    // previously auto-disabled row.
                    'consecutive_failure_count' => 0,
                    'last_error' => null,
                    'last_error_at' => null,
                    'disabled_reason' => null,
                ],
            );
        });

        $this->status = "Now watching {$candidate['name']}.";

        // Clear the results so the UI returns to "search" state
        // rather than leaving the just-added result on screen.
        $this->results = [];
        $this->resultStructureIds = [];
        $this->query = '';
    }

    /**
     * Remove one of the donor's own watched structures. We scope by
     * `owner_user_id` at the query level so a forged POST can't
     * delete another user's rows even if they guessed the row id.
     */
    public function removeStructure(int $rowId): void
    {
        $this->error = null;
        $this->status = null;

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        $row = MarketWatchedLocation::query()
            ->where('id', $rowId)
            ->where('owner_user_id', $user->id)
            ->first();
        if ($row === null) {
            $this->error = 'Watched structure not found.';

            return;
        }

        $name = $row->name ?? "#{$row->location_id}";
        $row->delete();
        $this->status = "Removed {$name} from your watched structures.";
    }
}
