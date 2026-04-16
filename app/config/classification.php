<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Donor classification system runtime config
|--------------------------------------------------------------------------
|
| Centralises the knobs the classification resolver + the supporting
| ESI ingestion jobs read. Everything here has a sensible default that
| matches the behaviour described in the migration headers, so an
| unconfigured deploy still produces correct results.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Corporation affiliation sweep staleness (hours)
    |--------------------------------------------------------------------------
    |
    | How long before the daily sweep will re-hit ESI for a corp's
    | alliance / history. A profile with `observed_at` within this
    | window is considered fresh and skipped on the next sweep.
    | Corp alliance moves are a day-scale event, so 24h picks up
    | the signal within one human cycle without burning ESI budget.
    |
    */
    'corp_affiliation_staleness_hours' => env('CLASSIFICATION_CORP_AFFILIATION_STALENESS_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Recent-change window (days)
    |--------------------------------------------------------------------------
    |
    | A `corporation_affiliation_profile` with `last_alliance_change_at`
    | newer than this window has `recently_changed_affiliation = true`,
    | which the donor-facing UI surfaces as a trust signal
    | ("this corp moved blocs last week — double-check your classification").
    | Matches the 14-day default documented in the migration header.
    |
    */
    'recent_change_days' => env('CLASSIFICATION_RECENT_CHANGE_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Standing thresholds
    |--------------------------------------------------------------------------
    |
    | The standing values at which the resolver interprets a contact as
    | friendly or hostile. Matches CCP's in-game ±5 threshold for
    | standing-applied behaviour (fleet autojoin, station services).
    | Pulled into config so a future tune (e.g. tighten to ±7.5 for
    | donor-support signal) doesn't require a code change.
    |
    */
    'standings' => [
        'friendly_at' => (float) env('CLASSIFICATION_STANDING_FRIENDLY_AT', 5.0),
        'hostile_at' => (float) env('CLASSIFICATION_STANDING_HOSTILE_AT', -5.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Affiliation freshness windows (days)
    |--------------------------------------------------------------------------
    |
    | Applied to CorporationAffiliationProfile.observed_at by the
    | alliance-inheritance (rung 5) and alliance-history (rung 6)
    | precedence rungs:
    |
    |   observed_at within `fresh_days`  → rung's natural confidence
    |   observed_at within `stale_days`  → confidence stepped down one
    |                                      band, row flagged needs_review
    |   observed_at beyond `stale_days`  → rung skipped entirely
    |
    | See migration
    | `2026_04_15_000004_create_corporation_affiliation_profiles_table.php`
    | for the policy rationale.
    |
    */
    'affiliation_freshness' => [
        'fresh_days' => (int) env('CLASSIFICATION_AFFILIATION_FRESH_DAYS', 7),
        'stale_days' => (int) env('CLASSIFICATION_AFFILIATION_STALE_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recompute sweep
    |--------------------------------------------------------------------------
    |
    | `staleness_days`: the nightly sweep marks a viewer's classification
    | rows is_dirty=1 when last_recomputed_at is older than this window.
    | Catches anything an upstream-change event didn't flag.
    |
    | `max_dirty_per_viewer`: plane-boundary-compliant cap on how many
    | dirty rows the RecomputeDirtyClassificationsJob processes per
    | viewer per dispatch. Keeps each job within the ≤100-row budget.
    |
    */
    'recompute' => [
        'staleness_days' => (int) env('CLASSIFICATION_RECOMPUTE_STALENESS_DAYS', 7),
        'max_dirty_per_viewer' => (int) env('CLASSIFICATION_RECOMPUTE_MAX_DIRTY', 50),
    ],

];
