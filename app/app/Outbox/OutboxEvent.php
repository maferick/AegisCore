<?php

declare(strict_types=1);

namespace App\Outbox;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Row in the `outbox` table (see docs/CONTRACTS.md § Plane boundary).
 *
 * Never instantiate this directly in domain code — go through
 * OutboxRecorder::record() so the event is written inside the same
 * transaction as the control-plane mutation it describes.
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $producer
 * @property int $version
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property int $attempts
 * @property string|null $last_error
 */
class OutboxEvent extends Model
{
    protected $table = 'outbox';

    // Append-only log: `updated_at` is not meaningful.
    // `created_at` is managed explicitly via the DB default, not Eloquent.
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'processed_at' => 'datetime',
        'version' => 'integer',
        'attempts' => 'integer',
    ];

    /** @param Builder<self> $query */
    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at')->orderBy('id');
    }
}
