<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domains\Markets\Models\MarketWatchedLocation;
use App\Domains\Markets\Services\StructurePickerService;
use App\Domains\UsersCharacters\Actions\SyncViewerContextForCharacter;
use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\CharacterStanding;
use App\Domains\UsersCharacters\Models\CharacterStandingLabel;
use App\Domains\UsersCharacters\Models\CoalitionBloc;
use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Domains\UsersCharacters\Models\ViewerContext;
use App\Domains\UsersCharacters\Services\CharacterStandingsFetcher;
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
 * Four surfaces on one page:
 *
 *   1. Identity card (static — read-only from the authed user).
 *   2. Market-data authorisation (donor-gated) — CTA, token status.
 *   3. Corp / alliance standings (donor-gated, donor-scoped display):
 *      read-only table of standings the donor's corp/alliance holds
 *      toward other corps/alliances/factions, with a "Sync now"
 *      button that runs {@see CharacterStandingsFetcher}. Individual-
 *      character contacts are filtered out (donor-UX rule: no
 *      personal grudges on a shared surface). Downstream: fuels the
 *      automatic battle-report friendly/enemy tagging for donors and
 *      admins. Non-donor manual reports ignore this table and use
 *      Team A / Team B instead.
 *   4. Watched structures (donor-gated) — interactive: search for
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

    public function render(SyncViewerContextForCharacter $sync): View
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
            'standings_by_owner' => $this->standingsByOwner(),
            'standings_token_missing_scopes' => $this->standingsTokenMissingScopes(),
            'viewer_bloc_state' => $this->viewerBlocState($sync),
            'coalition_blocs' => $this->coalitionBlocs(),
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

    /**
     * Standings visible to this user, grouped for the /account/settings
     * table. Scope is:
     *
     *   - 'corporation' bucket: standings owned by corps of any of the
     *     user's linked characters.
     *   - 'alliance' bucket: standings owned by alliances of any of
     *     the user's linked characters.
     *   - 'character' bucket: fallback — the donor's personal contact
     *     list, only surfaced when the fetcher had to fall back to it
     *     (i.e. no corp or alliance rows exist for this user's orgs).
     *
     * contact_type = 'character' is filtered OUT of display regardless
     * of owner bucket — personal grudges never render (donor-UX rule).
     * This is a filter over WHICH contacts show, not WHERE they come
     * from.
     *
     * Each row also carries a `labels` sub-collection — the label
     * names matching `label_ids`, looked up once per (owner_type,
     * owner_id) and mapped in memory so we don't N+1 per row.
     *
     * Returned shape:
     *
     *   [
     *     'corporation' => [
     *       'owner_id' => 98765432,
     *       'rows' => Collection<CharacterStanding + $row->labels list>,
     *     ],
     *     'alliance' => [ ... ],
     *     'character' => [ ... ],   // fallback only
     *   ]
     *
     * @return array<string, array{owner_id: int, rows: Collection<int, CharacterStanding>}>
     */
    private function standingsByOwner(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return [];
        }

        $corpIds = $user->characters
            ->pluck('corporation_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $allianceIds = $user->characters
            ->pluck('alliance_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $characterIds = $user->characters
            ->pluck('character_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $result = [];

        if ($corpIds !== []) {
            $result[CharacterStanding::OWNER_CORPORATION] = $this->loadStandingsBucket(
                CharacterStanding::OWNER_CORPORATION,
                $corpIds,
            );
        }

        if ($allianceIds !== []) {
            $result[CharacterStanding::OWNER_ALLIANCE] = $this->loadStandingsBucket(
                CharacterStanding::OWNER_ALLIANCE,
                $allianceIds,
            );
        }

        // Only surface the character bucket when it actually has rows
        // (fetcher fell back to it). Donors who got corp/alliance data
        // shouldn't see their personal contacts card on this page.
        if ($characterIds !== []) {
            $characterBucket = $this->loadStandingsBucket(
                CharacterStanding::OWNER_CHARACTER,
                $characterIds,
            );
            if ($characterBucket['rows']->isNotEmpty()) {
                $result[CharacterStanding::OWNER_CHARACTER] = $characterBucket;
            }
        }

        return $result;
    }

    /**
     * Load one owner-bucket's standings + attach a `labels` property
     * to each row (list of ['label_id' => int, 'label_name' => string]).
     *
     * The label lookup is one query per owner-bucket, not per row —
     * `label_ids` in `character_standings` is a JSON array, and we
     * hydrate the matching names from `character_standing_labels`
     * keyed by (owner_type, owner_id, label_id).
     *
     * @param  list<int>  $ownerIds
     * @return array{owner_id: int, rows: Collection<int, CharacterStanding>}
     */
    private function loadStandingsBucket(string $ownerType, array $ownerIds): array
    {
        $rows = CharacterStanding::query()
            ->where('owner_type', $ownerType)
            ->whereIn('owner_id', $ownerIds)
            ->whereIn('contact_type', [
                CharacterStanding::CONTACT_CORPORATION,
                CharacterStanding::CONTACT_ALLIANCE,
                CharacterStanding::CONTACT_FACTION,
            ])
            ->orderByDesc('standing')
            ->orderBy('contact_name')
            ->get();

        // Build an owner_id → (label_id → label_name) lookup in one
        // query so row hydration below is O(1) per row instead of a
        // per-row join.
        $labelMap = [];
        if ($rows->isNotEmpty()) {
            $presentOwnerIds = $rows->pluck('owner_id')->unique()->values()->all();
            CharacterStandingLabel::query()
                ->where('owner_type', $ownerType)
                ->whereIn('owner_id', $presentOwnerIds)
                ->get(['owner_id', 'label_id', 'label_name'])
                ->each(function (CharacterStandingLabel $label) use (&$labelMap): void {
                    $labelMap[$label->owner_id][$label->label_id] = $label->label_name;
                });
        }

        $rows->each(function (CharacterStanding $row) use ($labelMap): void {
            $ownerLabels = $labelMap[$row->owner_id] ?? [];
            $ids = is_array($row->label_ids) ? $row->label_ids : [];
            $hydrated = [];
            foreach ($ids as $id) {
                $idInt = (int) $id;
                $hydrated[] = [
                    'label_id' => $idInt,
                    'label_name' => $ownerLabels[$idInt] ?? null,
                ];
            }
            // Attach a dynamic attribute so the Blade view can render
            // the label chips without a separate lookup call.
            $row->setAttribute('hydrated_labels', $hydrated);
        });

        return [
            'owner_id' => (int) ($ownerIds[0] ?? 0),
            'rows' => $rows,
        ];
    }

    /**
     * Names of contact-scopes the donor's existing market token is
     * missing. Used by the view to nudge re-authorisation when the
     * token predates the standings rollout (existing tokens don't
     * carry the new scopes until the donor clicks "Re-authorise").
     *
     * @return list<string>
     */
    private function standingsTokenMissingScopes(): array
    {
        $token = $this->marketToken();
        if ($token === null) {
            return [];
        }

        $missing = [];
        foreach ([
            CharacterStandingsFetcher::SCOPE_CORP_CONTACTS,
            CharacterStandingsFetcher::SCOPE_ALLIANCE_CONTACTS,
            CharacterStandingsFetcher::SCOPE_CHARACTER_CONTACTS,
        ] as $scope) {
            if (! $token->hasScope($scope)) {
                $missing[] = $scope;
            }
        }

        return $missing;
    }

    /**
     * State for the coalition-affiliation card. Resolves (or lazy-
     * creates) the ViewerContext for the user's primary character and
     * returns a view-friendly shape with everything the Blade needs.
     *
     * "Primary character" for Phase 1 = first linked character by id.
     * Multi-character viewer-context management is a later slice.
     *
     * Return shape:
     *
     *   null                         — user has no linked characters
     *   [
     *     'context'             => ViewerContext,
     *     'character'           => Character,
     *     'bloc'                => CoalitionBloc | null,
     *     'is_confirmed'        => bool,
     *     'suggestion_bloc'     => CoalitionBloc | null (only when unresolved),
     *     'confidence_band'     => 'high'|'medium'|'low'|null,
     *   ]
     *
     * @return array<string, mixed>|null
     */
    private function viewerBlocState(SyncViewerContextForCharacter $sync): ?array
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        $character = $user->characters()->orderBy('id')->first();
        if ($character === null) {
            return null;
        }

        // Lazy-create / refresh on every render. The action no-ops on
        // an unchanged confirmed row; only touches DB on new rows or
        // affiliation drift.
        $context = $sync->handle($character);

        $bloc = $context->bloc_id !== null
            ? CoalitionBloc::query()->find($context->bloc_id)
            : null;

        return [
            'context' => $context,
            'character' => $character,
            'bloc' => $bloc,
            'is_confirmed' => ! $context->bloc_unresolved,
            // When unresolved but we have a suggestion, surface the
            // bloc as the suggestion rather than as confirmed truth.
            'suggestion_bloc' => $context->bloc_unresolved ? $bloc : null,
            'confidence_band' => $context->bloc_confidence_band,
        ];
    }

    /**
     * Active coalition blocs for the picker dropdown, ordered for a
     * stable display. Loaded once per render (tiny table, < 10 rows).
     *
     * @return Collection<int, CoalitionBloc>
     */
    private function coalitionBlocs(): Collection
    {
        return CoalitionBloc::query()
            ->where('is_active', true)
            ->orderBy('display_name')
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
     * Sync the donor's corp + alliance standings by running the
     * fetcher against their market token. Donor/admin only — same
     * gate as the market-data surface. The action is idempotent:
     * clicking twice in a row is a no-op on the second click because
     * the upsert writes the same data back.
     *
     * The fetcher hits ESI synchronously inside the request (token
     * refresh + at most a handful of pages per endpoint + one names
     * POST). For typical corp/alliance sizes this is well under the
     * 2-second job-placement rule; a pathologically large alliance
     * would be the one case to push to Horizon, but we'd notice that
     * in timings before it became a real problem.
     */
    public function syncStandings(CharacterStandingsFetcher $fetcher): void
    {
        $this->error = null;
        $this->status = null;

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }
        if (! ($user->isDonor() || $user->isAdmin())) {
            $this->error = 'Standings sync is a donor benefit.';

            return;
        }

        $token = $this->marketToken();
        if ($token === null) {
            $this->error = 'Authorise market data first — use the button above.';

            return;
        }

        try {
            $result = $fetcher->sync($token);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->status = $result->toFlashMessage();
    }

    /**
     * Confirm the currently-suggested bloc (whatever the resolver put
     * on the row). Flips bloc_unresolved=false. No-op if the row has
     * no bloc_id (the donor must pick via {@see self::setViewerBloc()}
     * first).
     */
    public function confirmViewerBloc(): void
    {
        $this->error = null;
        $this->status = null;

        $context = $this->primaryViewerContextOrFail();
        if ($context === null) {
            return;
        }

        if ($context->bloc_id === null) {
            $this->error = 'Pick a bloc from the dropdown first.';

            return;
        }

        $context->bloc_unresolved = false;
        $context->save();

        $this->status = 'Coalition affiliation confirmed.';
    }

    /**
     * Explicitly pick (or change) the viewer's bloc. Accepts any
     * active CoalitionBloc id. Sets confidence to 'high' because a
     * manual pick is a direct donor assertion. Flips bloc_unresolved
     * false in the same save — manual pick IS the confirmation.
     */
    public function setViewerBloc(int $blocId): void
    {
        $this->error = null;
        $this->status = null;

        $context = $this->primaryViewerContextOrFail();
        if ($context === null) {
            return;
        }

        $bloc = CoalitionBloc::query()->where('id', $blocId)->where('is_active', true)->first();
        if ($bloc === null) {
            $this->error = 'Unknown coalition bloc.';

            return;
        }

        $context->bloc_id = $bloc->id;
        $context->bloc_confidence_band = ViewerContext::CONFIDENCE_HIGH;
        $context->bloc_unresolved = false;
        $context->save();

        $this->status = "Coalition affiliation set to {$bloc->display_name}.";
    }

    /**
     * Re-open inference on the viewer context: clears confirmation,
     * re-runs the inference service against current labels on the
     * character's alliance/corp. Used when admins have added bloc
     * labels since the donor last looked and the donor wants the
     * fresh suggestion surfaced again.
     */
    public function reinferViewerBloc(SyncViewerContextForCharacter $sync): void
    {
        $this->error = null;
        $this->status = null;

        $context = $this->primaryViewerContextOrFail();
        if ($context === null) {
            return;
        }

        // Flip unresolved so the Action's inference branch runs.
        $context->bloc_unresolved = true;
        $context->save();

        $character = $context->character;
        if ($character === null) {
            $this->error = 'Linked character is missing.';

            return;
        }

        $sync->handle($character, forceReinfer: true);

        $this->status = 'Re-ran inference against the latest labels.';
    }

    /**
     * Load the primary viewer context for the authed user. Returns
     * null and sets $this->error if the user has no linked characters
     * or no viewer context yet (which should not happen after the
     * first render, since render() lazy-creates).
     */
    private function primaryViewerContextOrFail(): ?ViewerContext
    {
        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        $character = $user->characters()->orderBy('id')->first();
        if ($character === null) {
            $this->error = 'Link an EVE character first.';

            return null;
        }

        $context = ViewerContext::query()->where('character_id', $character->id)->first();
        if ($context === null) {
            // Shouldn't happen — render() lazy-creates. Guard anyway.
            $this->error = 'No viewer context found. Reload the page.';

            return null;
        }

        return $context;
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
