<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import ansiblex jump-bridge corridors from a CSV file.
 *
 * CSV format (one pair per line, with or without header):
 *   from_system_name,to_system_name[,alliance_id[,structure_id[,name]]]
 *
 * Example:
 *   Obe,JITA,99003581,,FRT - Obe » JITA
 *   OX-S7P,FR-WN5
 *
 * Usage:
 *   php artisan map:import-ansiblex path/to/bridges.csv
 */
class ImportAnsiblexCommand extends Command
{
    protected $signature = 'map:import-ansiblex {file : CSV file path}
                            {--truncate : Wipe existing rows before import}';

    protected $description = 'Import ansiblex jump-bridge corridors from a CSV file.';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Cannot read {$file}");
            return 1;
        }
        if ($this->option('truncate')) {
            DB::statement('TRUNCATE TABLE ansiblex_jump_bridges');
            $this->info('Truncated ansiblex_jump_bridges.');
        }

        $fh = fopen($file, 'r');
        if (! $fh) {
            $this->error("Cannot open {$file}");
            return 1;
        }
        $nameToId = DB::table('ref_solar_systems')->pluck('id', 'name')->all();

        $inserted = 0;
        $skipped = 0;
        $lineNo = 0;
        while (($row = fgetcsv($fh, 4096, ',', '"', '\\')) !== false) {
            $lineNo++;
            if (count($row) < 2) continue;
            $fromName = trim((string) $row[0]);
            $toName = trim((string) $row[1]);
            if ($fromName === '' || $toName === '') continue;
            if (strcasecmp($fromName, 'from_system_name') === 0) continue;  // skip header

            $fromId = $nameToId[$fromName] ?? null;
            $toId = $nameToId[$toName] ?? null;
            if ($fromId === null || $toId === null) {
                $this->warn("line {$lineNo}: unknown system name(s) — {$fromName} / {$toName}");
                $skipped++;
                continue;
            }
            $allianceId = isset($row[2]) && $row[2] !== '' ? (int) $row[2] : null;
            $structureId = isset($row[3]) && $row[3] !== '' ? (int) $row[3] : null;
            $name = isset($row[4]) && $row[4] !== '' ? (string) $row[4] : null;

            [$lo, $hi] = $fromId < $toId ? [$fromId, $toId] : [$toId, $fromId];
            DB::table('ansiblex_jump_bridges')->updateOrInsert(
                ['from_system_id' => $lo, 'to_system_id' => $hi],
                [
                    'alliance_id' => $allianceId,
                    'structure_id' => $structureId,
                    'name' => $name,
                    'last_seen_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $inserted++;
        }
        fclose($fh);
        $this->info("Imported {$inserted} pairs (skipped {$skipped}).");
        return 0;
    }
}
