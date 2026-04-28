<?php

declare(strict_types=1);

namespace App\Domains\Killmails\Services;

/**
 * Badge ladder for the public /war-report/{conflict}/me effort page.
 *
 * Each metric (kills / FBs / ISK destroyed / battles attended / solo+
 * small-gang kills) maps a participant's value to a tier 1-10 by
 * percentile rank vs every other character in the conflict's
 * killmail set. Tier 1 is elite (top 0.1%); tier 10 is "you logged
 * in once, hat's off". Top tiers stay EVE-flavored, the bottom half
 * leans into reddit-meme energy per operator request.
 *
 * Adding a metric: append a const to METRICS, add a TIER_LADDERS
 * row keyed by the metric, and the controller picks it up — the
 * percentile math is generic.
 */
final class WarEffortBadges
{
    /** @var list<string> */
    public const array METRICS = [
        'kills',
        'final_blows',
        'isk_destroyed',
        'battles_attended',
        'small_gang_kills',
    ];

    /**
     * Percentile thresholds (values below = better tier). The ten
     * cutoffs are in ascending percentile, applied left-to-right;
     * once a participant's percentile is below a cutoff, that's
     * their tier. 100.1 catches everyone the prior cutoffs missed.
     *
     * @var list<float>
     */
    public const array TIER_PERCENTILES = [
        0.1,    // tier 1 — top 0.1%
        0.5,    // tier 2 — top 0.5%
        1.0,    // tier 3
        2.5,    // tier 4
        5.0,    // tier 5
        10.0,   // tier 6
        25.0,   // tier 7
        50.0,   // tier 8
        75.0,   // tier 9
        100.1,  // tier 10 — everyone else who showed up
    ];

    /**
     * @return array<int, array{name: string, sub: string}>
     *         tier (1..10) → {display name, one-line flavor}
     */
    public static function ladder(string $metric): array
    {
        return self::TIER_LADDERS[$metric] ?? self::TIER_LADDERS['kills'];
    }

    /**
     * Resolve a percentile (0-100, lower is better) to a tier 1..10.
     */
    public static function tierForPercentile(float $percentile): int
    {
        foreach (self::TIER_PERCENTILES as $i => $cutoff) {
            if ($percentile <= $cutoff) {
                return $i + 1;
            }
        }
        return 10;
    }

    /** 30-tier overall ladder. Lower index = better.
     *  Top 1-10 EVE-flavored, 11-20 mid, 21-30 reddit-meme.
     *
     *  @var list<string>
     */
    public const array OVERALL_LADDER = [
        1  => 'Capsuleer of the Era',
        2  => 'Apex Operator',
        3  => 'Bane of Their Bloc',
        4  => 'Standing FC Material',
        5  => 'Killmail Royalty',
        6  => 'Veteran of Every Ping',
        7  => 'Subcap Sovereign',
        8  => 'Capital Hunter',
        9  => 'Black Ops Aficionado',
        10 => 'Frontline Anchor',
        11 => 'Reliable Body',
        12 => 'Solid Mid-Fleet',
        13 => 'Logi Loved You Too',
        14 => 'Could-Have-Done-Worse',
        15 => 'Gets the Job Done-ish',
        16 => 'Press F to Anchor',
        17 => 'Showed Up With Pants On',
        18 => 'Logged On Today',
        19 => 'Standby Tackle',
        20 => 'I Heard There Were Pings',
        21 => 'It Is Wednesday My Dudes',
        22 => 'Number Goes Up Sometimes',
        23 => 'Such Battle. Very Loss.',
        24 => 'Permabanned From Local',
        25 => 'I Lost My Pod Goggles',
        26 => 'Flew Into A Stargate Fast',
        27 => 'Cargo Hold Full of Hopes',
        28 => 'I Yelled In Comms Once',
        29 => 'Touched Grass IRL',
        30 => 'I Heard We Have A Discord',
    ];

    /**
     * @param  array<string, array{tier:int}>  $perMetricTiers   metric → {tier}
     * @return array{bucket:int, name:string, avg_tier:float}
     */
    public static function overallBadge(array $perMetricTiers): array
    {
        if ($perMetricTiers === []) {
            return ['bucket' => 30, 'name' => self::OVERALL_LADDER[30], 'avg_tier' => 10.0];
        }
        $tiers = array_map(fn ($x) => (int) ($x['tier'] ?? 10), $perMetricTiers);
        $avg = array_sum($tiers) / count($tiers);
        // Map avg 1.0..10.0 onto bucket 1..30 (lower = better).
        $bucket = (int) max(1, min(30, round((($avg - 1) * 29 / 9) + 1)));
        return [
            'bucket' => $bucket,
            'name' => self::OVERALL_LADDER[$bucket] ?? self::OVERALL_LADDER[30],
            'avg_tier' => round($avg, 2),
        ];
    }

    /**
     * @var array<string, array<int, array{name: string, sub: string}>>
     */
    public const array TIER_LADDERS = [
        'kills' => [
            1  => ['name' => 'Apex Predator',          'sub' => 'top 0.1% — your name shows up in CCP devblogs'],
            2  => ['name' => 'Fleet Anchor of Legend', 'sub' => 'top 0.5% — line FCs warp to YOU'],
            3  => ['name' => 'Killmail Royalty',       'sub' => 'top 1% — zKill stalks you'],
            4  => ['name' => 'Pod Crusher',            'sub' => 'top 2.5%'],
            5  => ['name' => 'Veteran of a Thousand Fights', 'sub' => 'top 5%'],
            6  => ['name' => 'Subcap Slayer',          'sub' => 'top 10%'],
            7  => ['name' => 'Warm Body Confirmed',    'sub' => 'top 25% — you do indeed undock'],
            8  => ['name' => 'Press F to Anchor',      'sub' => 'top 50% — the bare minimum, respected'],
            9  => ['name' => 'It Is Wednesday My Dudes', 'sub' => 'top 75% — you exist and that counts'],
            10 => ['name' => 'I Shidded And Farded',   'sub' => "everyone gets a participation pin"],
        ],
        'final_blows' => [
            1  => ['name' => 'The Killing Blow',        'sub' => 'top 0.1% — FB legend'],
            2  => ['name' => 'Sniper of Last Cycle',   'sub' => 'top 0.5%'],
            3  => ['name' => 'Mail Whore',             'sub' => 'top 1% — they call you that lovingly'],
            4  => ['name' => 'Final Word',             'sub' => 'top 2.5%'],
            5  => ['name' => 'Trigger Discipline',     'sub' => 'top 5%'],
            6  => ['name' => 'Last One On The Mail',   'sub' => 'top 10%'],
            7  => ['name' => 'Lucky Volley',           'sub' => 'top 25%'],
            8  => ['name' => 'Skill Issue (yours, not theirs)', 'sub' => 'top 50%'],
            9  => ['name' => 'Pikachu Surprised Face', 'sub' => 'top 75% — wait, that hit?'],
            10 => ['name' => 'This Is Fine',           'sub' => "you tried, the dog is on fire"],
        ],
        'isk_destroyed' => [
            1  => ['name' => 'Wallet Apocalypse',      'sub' => 'top 0.1% — economic warfare incarnate'],
            2  => ['name' => 'ISK Genocidaire',        'sub' => 'top 0.5%'],
            3  => ['name' => 'Hangar Flattener',       'sub' => 'top 1%'],
            4  => ['name' => 'Insurance Frauder',      'sub' => 'top 2.5%'],
            5  => ['name' => 'Wallet Whisperer',       'sub' => 'top 5%'],
            6  => ['name' => 'Loot Fairy Foe',         'sub' => 'top 10%'],
            7  => ['name' => 'Number Go Up',           'sub' => 'top 25% — you do indeed kill expensive things'],
            8  => ['name' => 'Yeah Science!',          'sub' => 'top 50%'],
            9  => ['name' => 'Such Damage. Very Wow.', 'sub' => 'top 75%'],
            10 => ['name' => 'Big Ouch Energy',        'sub' => "you did the bare ouch"],
        ],
        'battles_attended' => [
            1  => ['name' => 'Always-On',              'sub' => 'top 0.1% — does this person sleep'],
            2  => ['name' => 'Fleet Junkie',           'sub' => 'top 0.5%'],
            3  => ['name' => 'Standing FC Pet',        'sub' => 'top 1%'],
            4  => ['name' => 'Ping Responder Premium', 'sub' => 'top 2.5%'],
            5  => ['name' => 'X Up Specialist',        'sub' => 'top 5%'],
            6  => ['name' => 'Reliable Body',          'sub' => 'top 10%'],
            7  => ['name' => 'Showed Up Mostly',       'sub' => 'top 25%'],
            8  => ['name' => 'Sometimes I X-Up',       'sub' => 'top 50% — no wrong answers'],
            9  => ['name' => 'AFK At Spawn',           'sub' => 'top 75%'],
            10 => ['name' => 'I Heard There Was Pings', 'sub' => "you saw a battle. it counts."],
        ],
        'small_gang_kills' => [
            1  => ['name' => 'Tuskers-Tier Solo',      'sub' => 'top 0.1% small-gang masters'],
            2  => ['name' => 'Probe-And-Pop',          'sub' => 'top 0.5%'],
            3  => ['name' => 'Off-Grid Fox',           'sub' => 'top 1%'],
            4  => ['name' => 'Frigate Whisperer',      'sub' => 'top 2.5%'],
            5  => ['name' => 'Pipe Camper',            'sub' => 'top 5%'],
            6  => ['name' => 'Solo Roamer',            'sub' => 'top 10%'],
            7  => ['name' => 'Caught One Slipping',    'sub' => 'top 25%'],
            8  => ['name' => 'Lucky Tackle',           'sub' => 'top 50%'],
            9  => ['name' => 'Red on Local',           'sub' => 'top 75%'],
            10 => ['name' => 'I Touched Grass',        'sub' => "all kills are blob kills, but you're proud"],
        ],
    ];
}
