<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| sde_version_checks — drift detection between pinned SDE and upstream
|--------------------------------------------------------------------------
|
| One row per daily check by `reference:check-sde-version`. The latest row
| drives the Filament admin widget ("SDE up-to-date" / "SDE drift: N days");
| the full history backs the /admin/sde-status page and gives ops a trail
| of when CCP bumped the snapshot vs. when we caught up.
|
| Not a domain pillar — reference data is cross-cutting. Lives under
| app/Reference/, which sits outside app/Domains/ (see ADR-0001).
|
*/
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sde_version_checks', function (Blueprint $table) {
            $table->bigIncrements('id');

            // UTC timestamp when the HEAD request went out.
            $table->timestamp('checked_at', 6);

            // Value from infra/sde/version.txt at check time. Null if the
            // marker is missing — "no snapshot loaded yet".
            $table->string('pinned_version', 64)->nullable();

            // Best-effort upstream version. Populated from ETag or the
            // file name in the Content-Disposition header when CCP
            // provides one; otherwise a digest of Last-Modified. Null
            // means the HEAD failed or upstream didn't expose a
            // version-shaped header.
            $table->string('upstream_version', 128)->nullable();

            // Raw headers we care about, kept for audit + debugging. CCP
            // occasionally changes what they expose — surfacing these
            // means we can diagnose "why did the widget stop updating"
            // without re-HEADing.
            $table->string('upstream_etag', 128)->nullable();
            $table->string('upstream_last_modified', 64)->nullable();

            // Computed at insert time: pinned != upstream and both are
            // non-null. Cached so the widget doesn't re-derive it on
            // every dashboard render.
            $table->boolean('is_bump_available')->default(false);

            // HTTP status + free-text notes. On upstream hiccups
            // (5xx, timeout) we still write a row so the widget can
            // distinguish "check ran, no bump" from "check stalled".
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('created_at', 6)->useCurrent();

            // Widget query: latest row overall.
            $table->index('checked_at', 'idx_checked_at');

            // History query scoped to bumps only.
            $table->index(['is_bump_available', 'checked_at'], 'idx_bumps');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sde_version_checks');
    }
};
