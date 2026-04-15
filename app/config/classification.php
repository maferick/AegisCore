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

];
