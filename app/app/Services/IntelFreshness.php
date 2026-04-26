<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Phase 4.9 — operator-side freshness classifier.
 *
 * Mirrors python/counter_intel/phase4_freshness.SURFACE_TTL.
 * The Python compute pre-fills the freshness_state column on
 * each row; this helper re-evaluates at render time so a row
 * that was 'fresh' an hour ago shows 'aging' on the next page
 * load — the column is the floor, the live computation is the
 * authoritative ceiling.
 */
final class IntelFreshness
{
    /**
     * Per-surface TTL ladder in hours: [fresh, aging, stale].
     * Anything past `stale` is `expired`.
     */
    public const SURFACE_TTL = [
        'digest'             => [6, 24, 72],
        'alert'              => [1, 6, 24],
        'incident'           => [0.5, 6, 48],
        'cluster'            => [0.5, 6, 48],
        'corridor'           => [24, 24 * 7, 24 * 30],
        'force_composition'  => [24, 24 * 7, 24 * 30],
        'threat_surface'     => [24, 24 * 7, 24 * 14],
        'alliance_profile'   => [24, 24 * 7, 24 * 30],
        'coalition'          => [24, 24 * 7, 24 * 30],
        'narrative'          => [6, 24, 24 * 7],
        'doctrine_evolution' => [24 * 7, 24 * 30, 24 * 90],
        'verified'           => [24 * 7, 24 * 30, 24 * 90],
    ];

    public const STATES = ['fresh', 'aging', 'stale', 'expired'];

    public const STATE_COLORS = [
        'fresh' => '#86efac',
        'aging' => '#fde68a',
        'stale' => '#fdba74',
        'expired' => '#fb7185',
    ];

    /**
     * Classify a single timestamp under a surface's TTL ladder.
     * Returns one of fresh / aging / stale / expired. NULL timestamp
     * → 'expired'.
     */
    public static function classify(string $surface, ?string $timestamp, ?DateTimeInterface $now = null): string
    {
        if ($timestamp === null) return 'expired';
        $ttl = self::SURFACE_TTL[$surface] ?? [24, 24 * 7, 24 * 30];

        $ref = ($now instanceof CarbonInterface) ? $now : Carbon::now('UTC');
        try {
            $ts = Carbon::parse($timestamp, 'UTC');
        } catch (\Throwable) {
            return 'expired';
        }
        $hours = $ts->diffInMinutes($ref, true) / 60.0;
        if ($hours <= $ttl[0]) return 'fresh';
        if ($hours <= $ttl[1]) return 'aging';
        if ($hours <= $ttl[2]) return 'stale';
        return 'expired';
    }

    /**
     * Resolve the effective state. Prefers the live re-evaluation
     * over a possibly-cold persisted column. The persisted column
     * is the upper-bound — once a row is classified expired by
     * compute, we never resurrect it (aged-out content stays aged).
     */
    public static function resolve(string $surface, ?string $timestamp, ?string $persisted = null, ?DateTimeInterface $now = null): string
    {
        $live = self::classify($surface, $timestamp, $now);
        if ($persisted === null) return $live;
        $rank = array_flip(self::STATES);
        return ($rank[$live] ?? 0) > ($rank[$persisted] ?? 0) ? $live : $persisted;
    }

    /**
     * Render a freshness pill for inline use in blade templates.
     * Returns inline-styled HTML; blade should wrap in `{!! !!}`.
     */
    public static function pill(string $surface, ?string $timestamp, ?string $persisted = null, ?string $sourceWindowStart = null, ?string $sourceWindowEnd = null): string
    {
        $state = self::resolve($surface, $timestamp, $persisted);
        $col = self::STATE_COLORS[$state] ?? '#9ca3af';

        $age = '';
        if ($timestamp !== null) {
            try {
                $ts = Carbon::parse($timestamp, 'UTC');
                $age = ' · ' . $ts->diffForHumans(['parts' => 1, 'short' => true]);
            } catch (\Throwable) {
                // ignore — render without age
            }
        }

        $window = '';
        if ($sourceWindowStart && $sourceWindowEnd && $sourceWindowStart !== $sourceWindowEnd) {
            $window = ' · src ' . htmlspecialchars(self::shortWindow($sourceWindowStart, $sourceWindowEnd), ENT_QUOTES);
        }

        return '<span title="surface=' . htmlspecialchars($surface, ENT_QUOTES) . '"'
            . ' style="font-size:0.55rem; padding:1px 6px; border-radius:3px;'
            . ' background:rgba(255,255,255,0.04); color:' . $col . ';'
            . ' text-transform:uppercase; letter-spacing:0.06em;">'
            . htmlspecialchars($state, ENT_QUOTES)
            . htmlspecialchars($age, ENT_QUOTES)
            . htmlspecialchars($window, ENT_QUOTES)
            . '</span>';
    }

    private static function shortWindow(string $start, string $end): string
    {
        try {
            $s = Carbon::parse($start, 'UTC');
            $e = Carbon::parse($end, 'UTC');
            // Same day: HH:MM → HH:MM
            if ($s->isSameDay($e)) {
                return $s->format('m-d H:i') . '→' . $e->format('H:i');
            }
            return $s->format('m-d') . '→' . $e->format('m-d');
        } catch (\Throwable) {
            return '';
        }
    }
}
