<?php

declare(strict_types=1);

namespace App\Outbox;

use Illuminate\Support\Facades\DB;

/**
 * The single entry point for emitting events across the plane boundary.
 *
 * Usage inside a control-plane action:
 *
 *     DB::transaction(function () use ($killmail, $recorder) {
 *         $killmail->save();
 *         $recorder->record(new KillmailIngested($killmail));
 *     });
 *
 * The recorder does NOT start its own transaction. The caller is
 * responsible for wrapping the mutation + record() in one, so atomicity
 * is explicit at the call site rather than hidden here.
 */
final class OutboxRecorder
{
    public function record(DomainEvent $event): OutboxEvent
    {
        if (! DB::transactionLevel()) {
            throw new \LogicException(
                'OutboxRecorder::record() must be called inside a DB transaction '
                .'so the outbox row and the control-plane mutation commit together. '
                .'Wrap the call in DB::transaction(...).',
            );
        }

        return OutboxEvent::create([
            'event_id' => $event->eventId,
            'event_type' => $event::EVENT_TYPE,
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'producer' => 'laravel',
            'version' => $event::VERSION,
            'payload' => $event->payload(),
            'created_at' => now(),
        ]);
    }
}
