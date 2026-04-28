<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * killmails.total_value is computed from EveRef historical pricing,
 * which under-prices capital+ hulls (titan/super/carrier/dread/FAX).
 * Audit on 2026-04-28 against zKill: titans 60B (us) vs 140-165B
 * (zkill), supers 20B vs 50-65B — 50-58% underprice.
 *
 * Add columns to store zKill's totalValue / fittedValue per killmail
 * so the war report + battle dashboards can prefer the higher of
 * the two without touching the EveRef-driven valuation pipeline.
 * Populated lazily by `php artisan app:backfill-zkill-capital-values`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('killmails', function (Blueprint $table): void {
            $table->decimal('zkill_total_value', 20, 2)->nullable()->after('total_value');
            $table->decimal('zkill_fitted_value', 20, 2)->nullable()->after('zkill_total_value');
            $table->datetime('zkill_value_fetched_at')->nullable()->after('zkill_fitted_value');
        });
    }

    public function down(): void
    {
        Schema::table('killmails', function (Blueprint $table): void {
            $table->dropColumn(['zkill_total_value', 'zkill_fitted_value', 'zkill_value_fetched_at']);
        });
    }
};
