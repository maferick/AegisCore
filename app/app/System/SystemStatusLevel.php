<?php

declare(strict_types=1);

namespace App\System;

/**
 * Health level for a single backend component.
 *
 * Maps directly to the three-colour traffic-light the admin widget renders:
 *   OK       → green  (success)
 *   DEGRADED → orange (warning)
 *   DOWN     → red    (danger)
 *   UNKNOWN  → grey   (not configured / never checked)
 *
 * Kept deliberately coarse — the widget is a glance tool, not a monitoring
 * dashboard. Deeper diagnostics live in Horizon, OpenSearch Dashboards, etc.
 */
enum SystemStatusLevel: string
{
    case OK = 'ok';
    case DEGRADED = 'degraded';
    case DOWN = 'down';
    case UNKNOWN = 'unknown';

    /**
     * Filament stat colour token for this level.
     */
    public function color(): string
    {
        return match ($this) {
            self::OK => 'success',
            self::DEGRADED => 'warning',
            self::DOWN => 'danger',
            self::UNKNOWN => 'gray',
        };
    }

    /**
     * Heroicon name used next to the status line.
     */
    public function icon(): string
    {
        return match ($this) {
            self::OK => 'heroicon-m-check-circle',
            self::DEGRADED => 'heroicon-m-exclamation-triangle',
            self::DOWN => 'heroicon-m-x-circle',
            self::UNKNOWN => 'heroicon-m-question-mark-circle',
        };
    }

    /**
     * Short human label — renders as the stat value.
     */
    public function label(): string
    {
        return match ($this) {
            self::OK => 'Healthy',
            self::DEGRADED => 'Degraded',
            self::DOWN => 'Down',
            self::UNKNOWN => 'Unknown',
        };
    }
}
