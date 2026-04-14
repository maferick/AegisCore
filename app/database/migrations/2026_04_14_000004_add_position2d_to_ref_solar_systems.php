<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| ref_solar_systems — add position2D_x / position2D_y
|--------------------------------------------------------------------------
|
| CCP's SDE ships a schematic 2D position (`position2D`) for solar systems
| that the in-game 2D map renders against. The 3D `position` is a literal
| metres-from-cluster-centre value with EVE's per-region clustering; the
| 2D variant has been hand-laid by CCP for legibility. The map renderer
| module prefers position2D when present and falls back to a top-down XZ
| projection of the 3D position otherwise.
|
| Nullable because not every system / not every SDE snapshot ships the
| pair. The Python SDE importer (`python/sde_importer/schema.py`) maps
| `position2D.x` / `position2D.y` into these columns when they exist.
|
| See `app/app/Reference/Map/` for how the renderer consumes them.
|
*/
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ref_solar_systems', function (Blueprint $t) {
            $t->double('position2d_x')->nullable()->after('position_z');
            $t->double('position2d_y')->nullable()->after('position2d_x');
        });
    }

    public function down(): void
    {
        Schema::table('ref_solar_systems', function (Blueprint $t) {
            $t->dropColumn(['position2d_x', 'position2d_y']);
        });
    }
};
