<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| battle_theater_side_overrides — operator corrections layered on top of
| the auto-clustered side resolution (ADR-0006 § 2 addendum).
|--------------------------------------------------------------------------
|
| The side resolver is heuristic: kill-graph clustering + alliance pilot
| counts give the right answer 80% of the time. This table captures the
| remaining 20% — an operator marking a specific alliance / corp /
| character as Side B in the Krirald fight where the kill graph couldn't
| draw them in, or marking a roaming gang as a third party even when
| their kills are heavy on one side.
|
| Scope is per-theater. An alliance can be Side A in one battle and
| Side B in another — the unique index is on (theater_id, entity_type,
| entity_id) so the same entity has at most one override per fight.
|
| Precedence when the viewer renders: character override > corp
| override > alliance override > auto-resolver output. Exclude marks
| the entity as noise and removes its pilots / kills from the report
| entirely.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_theater_side_overrides', function (Blueprint $t) {
            $t->id();

            $t->unsignedBigInteger('theater_id');
            $t->string('entity_type', 20);    // 'alliance' | 'corporation' | 'character'
            $t->unsignedBigInteger('entity_id');

            // 'A' | 'B' | 'C' | 'exclude'
            //
            //   A/B/C — reassign the entity to that side, overriding
            //           the auto-resolver.
            //   exclude — drop every killmail/participant for this
            //             entity from the report.
            $t->string('side', 10);

            $t->unsignedBigInteger('actor_user_id')->nullable();

            $t->timestamps();

            $t->unique(['theater_id', 'entity_type', 'entity_id'], 'uq_override_key');
            $t->index('theater_id');
            $t->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_theater_side_overrides');
    }
};
