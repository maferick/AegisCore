<?php

declare(strict_types=1);

namespace App\Domains\Markets\Models;

use App\Models\User;
use App\Reference\Models\Region;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canonical market source — one row per unique (location_type, location_id).
 *
 * The policy + UX layer for ADR-0005's Private Market Hub overlay. A
 * hub is not "owned" by any donor: it is a stable, canonical identity
 * for a market source that survives donor churn, collector loss, and
 * admin turnover. Attached via:
 *
 *   - `collectors()`    — tokens authorised to poll this hub.
 *   - `entitlements()`  — subjects (user/corp/alliance) allowed to
 *                         view this hub's data, subject to the feature
 *                         entitlement intersection check.
 *
 * Two categories of hub:
 *
 *   - `is_public_reference = true`   — Jita and any other NPC hub the
 *                                      platform treats as publicly
 *                                      visible reference data. Access
 *                                      policy short-circuits — no
 *                                      donor check, no entitlement
 *                                      check, visible to everyone.
 *   - `is_public_reference = false`  — Donor / admin-registered
 *                                      player structure. Access gated
 *                                      by the intersection rule
 *                                      (see MarketHubAccessPolicy).
 *
 * The `watchedLocations()` relation points back at the poller's
 * driver table (market_watched_locations), which is the physical
 * polling lane. ADR-0005 § Transition explains why the two tables
 * coexist during the phase-out of owner-centric polling.
 *
 * @property int                  $id
 * @property string               $location_type
 * @property int                  $location_id
 * @property int                  $region_id
 * @property int|null             $solar_system_id
 * @property string|null          $structure_name
 * @property bool                 $is_public_reference
 * @property bool                 $is_active
 * @property int|null             $created_by_user_id
 * @property int|null             $active_collector_character_id
 * @property CarbonInterface|null $last_sync_at
 * @property CarbonInterface|null $last_access_verified_at
 * @property string|null          $disabled_reason
 * @property CarbonInterface      $created_at
 * @property CarbonInterface      $updated_at
 */
class MarketHub extends Model
{
    public const LOCATION_TYPE_NPC_STATION = 'npc_station';

    public const LOCATION_TYPE_PLAYER_STRUCTURE = 'player_structure';

    /** Mirrors the MarketWatchedLocation Jita-baseline constants. */
    public const JITA_REGION_ID = 10000002;

    public const JITA_LOCATION_ID = 60003760;

    protected $table = 'market_hubs';

    protected $fillable = [
        'location_type',
        'location_id',
        'region_id',
        'solar_system_id',
        'structure_name',
        'is_public_reference',
        'is_active',
        'created_by_user_id',
        'active_collector_character_id',
        'last_sync_at',
        'last_access_verified_at',
        'disabled_reason',
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'region_id' => 'integer',
            'solar_system_id' => 'integer',
            'is_public_reference' => 'boolean',
            'is_active' => 'boolean',
            'created_by_user_id' => 'integer',
            'active_collector_character_id' => 'integer',
            'last_sync_at' => 'datetime',
            'last_access_verified_at' => 'datetime',
        ];
    }

    /**
     * Belt-and-braces mirror of MarketWatchedLocation's Jita guard:
     * the canonical Jita 4-4 hub is the platform's public reference
     * baseline and must not be deletable from a Filament button,
     * tinker session, or stray ->delete() in a future service.
     *
     * The guard keys on the Jita IDs AND is_public_reference = true
     * — a hypothetical donor-registered hub that happened to reuse
     * the Jita location_id (not actually possible today, but
     * belt-and-braces) would not be protected.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $model): void {
            if (
                $model->is_public_reference
                && (int) $model->region_id === self::JITA_REGION_ID
                && (int) $model->location_id === self::JITA_LOCATION_ID
            ) {
                throw new DomainException(
                    'Jita 4-4 is the platform baseline and cannot be deleted. '
                    .'Toggle `is_active` if you want to pause polling.'
                );
            }
        });
    }

    // -- relations --------------------------------------------------------

    /**
     * Collectors (tokens) authorised to poll this hub. Zero for
     * public-reference hubs (Jita); one-or-more for private hubs.
     */
    public function collectors(): HasMany
    {
        return $this->hasMany(MarketHubCollector::class, 'hub_id');
    }

    /**
     * Entitlement rows granting viewer access. Zero for
     * public-reference hubs (the access policy short-circuits);
     * one-or-more for private hubs.
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(MarketHubEntitlement::class, 'hub_id');
    }

    /**
     * The watched-locations driver rows pointing at this hub. One-to-one
     * in the transition era; kept as hasMany so future poller
     * refactors (regional proxies, etc.) don't require a schema
     * change.
     */
    public function watchedLocations(): HasMany
    {
        return $this->hasMany(MarketWatchedLocation::class, 'hub_id');
    }

    /**
     * The user who first registered this hub. Audit metadata only —
     * does NOT confer ownership per ADR-0005 § Ownership. NULL for
     * Jita and other platform-seeded rows.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    // -- scopes -----------------------------------------------------------

    /** Public-reference (NPC / Jita-style) hubs only. */
    public function scopePublicReference($query)
    {
        return $query->where('is_public_reference', true);
    }

    /** Private (donor-registered) hubs only. */
    public function scopePrivate($query)
    {
        return $query->where('is_public_reference', false);
    }

    /** Active hubs only — the poller + UI default filter. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // -- predicates -------------------------------------------------------

    public function isJita(): bool
    {
        return $this->is_public_reference
            && (int) $this->region_id === self::JITA_REGION_ID
            && (int) $this->location_id === self::JITA_LOCATION_ID;
    }

    public function isPublicReference(): bool
    {
        return (bool) $this->is_public_reference;
    }

    public function isPrivate(): bool
    {
        return ! $this->is_public_reference;
    }
}
