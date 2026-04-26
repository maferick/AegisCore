<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\CounterIntel\Services\CounterIntelDossierService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * counter-intel:top-review-sample — surfaces the top N candidates for
 * human review by Phase 1 flag density inside a viewer bloc. Output
 * goes to verification/ci-phase1/top<N>_review.md and stdout.
 *
 * Used during calibration: directors validate the surfaced sample
 * against ci_character_ground_truth labels before tightening the
 * Phase 2 thresholds.
 */
class CounterIntelTopReviewSampleCommand extends Command
{
    protected $signature = 'counter-intel:top-review-sample
        {--bloc= : viewer bloc id}
        {--limit=100 : number of rows to render}
        {--out=verification/ci-phase1/top100_review.md : output markdown path (relative to repo root)}
        {--candidate-pool=2000 : how many candidates to score before picking top N}';

    protected $description = 'Render the top N CI Phase 1 review candidates for a viewer bloc into a markdown sample.';

    public function handle(CounterIntelDossierService $svc): int
    {
        $blocId = (int) $this->option('bloc');
        $limit = (int) $this->option('limit');
        $pool = (int) $this->option('candidate-pool');
        if ($blocId <= 0) {
            $this->error('Pass --bloc=<viewer_bloc_id>.');
            return self::FAILURE;
        }
        $blocName = DB::table('coalition_blocs')->where('id', $blocId)->value('display_name')
            ?? "Bloc #{$blocId}";

        $this->info("Building top-{$limit} review sample for {$blocName} (id={$blocId})…");

        // Friendly alliance set = alliances labelled to this viewer bloc.
        // Used to tag rows as in-bloc (true spy candidate) vs out-of-bloc
        // (signal validation — bloc-relative metrics fire baseline-true
        // for opposing-alliance members, so they're not directly
        // actionable for this bloc's review queue).
        $friendlyAlliances = DB::table('coalition_entity_labels')
            ->where('entity_type', 'alliance')
            ->where('is_active', 1)
            ->where('bloc_id', $blocId)
            ->pluck('entity_id')
            ->map(fn ($v) => (int) $v)
            ->all();
        $friendlySet = array_flip($friendlyAlliances);

        // Candidate pool: existing pre-ranked priority band + Phase 1
        // signal column hints. We over-fetch then re-rank by Phase 1
        // flag count so chars whose only signal is the existing
        // priority score (without Phase 1 evidence) drop down.
        $candidates = DB::table('ci_character_anomalies_rolling AS a')
            ->where('a.viewer_bloc_id', $blocId)
            ->where('a.window_end_date', function ($q) use ($blocId): void {
                $q->select(DB::raw('MAX(window_end_date)'))
                    ->from('ci_character_anomalies_rolling')
                    ->where('viewer_bloc_id', $blocId);
            })
            ->whereIn('a.review_priority_band', ['critical', 'high', 'elevated'])
            ->orWhere(function ($q) use ($blocId): void {
                // Also pull in Phase 1 fast-track candidates so we
                // don't miss strong Phase 1 hits that pre-Phase-1
                // priority_band missed.
                $q->where('a.viewer_bloc_id', $blocId)
                    ->where(function ($qq): void {
                        $qq->where('a.community_hostile_pct', '>=', 0.60)
                            ->orWhere('a.asymmetric_top_pair_outbound_pct', '>=', 0.40);
                    });
            })
            ->orderByDesc('a.review_priority_score')
            ->limit($pool)
            ->pluck('a.character_id')
            ->all();

        $this->info('  candidate pool: ' . count($candidates));

        $rows = [];
        $bar = $this->output->createProgressBar(count($candidates));
        $bar->start();
        foreach ($candidates as $cid) {
            $bar->advance();
            $d = $svc->dossier((int) $cid, $blocId);
            if (! empty($d['not_found'])) continue;
            $p1 = $d['phase1_signals'] ?? null;
            if ($p1 === null) continue;
            $allyId = $d['affiliation']['current']['alliance_id'] ?? null;
            $rows[] = [
                'character_id' => (int) $cid,
                'character_name' => $d['character_name'] ?? "#{$cid}",
                'corp_id' => $d['affiliation']['current']['corp_id'] ?? null,
                'corp_name' => $d['affiliation']['current']['corp_name'] ?? null,
                'alliance_id' => $allyId,
                'alliance_name' => $d['affiliation']['current']['alliance_name'] ?? null,
                'in_bloc' => $allyId !== null && isset($friendlySet[$allyId]),
                'flag_count' => (int) ($p1['flag_count'] ?? 0),
                'note_count' => (int) ($p1['note_count'] ?? 0),
                'phase1_band' => $p1['band'] ?? 'clean',
                'existing_band' => $d['anomaly']['review_priority_band'] ?? null,
                'existing_score' => $d['anomaly']['review_priority_score'] ?? null,
                'cohort_size' => $d['anomaly']['cohort_size'] ?? null,
                'cohort_confidence' => $d['anomaly']['cohort_confidence'] ?? null,
                'feature' => $d['feature'] ?? [],
                'signals' => $p1['signals'] ?? [],
            ];
        }
        $bar->finish();
        $this->newLine(2);

        // Rank: flag_count DESC, note_count DESC, existing_score DESC.
        $rank = function (array $a, array $b): int {
            return [$b['flag_count'], $b['note_count'], (float) ($b['existing_score'] ?? 0)]
                <=> [$a['flag_count'], $a['note_count'], (float) ($a['existing_score'] ?? 0)];
        };
        usort($rows, $rank);

        // Segregate. In-bloc takes the first half of the limit so spy
        // candidates surface even when out-of-bloc signal-validation
        // rows would otherwise dominate the head of the table.
        $inBlocRows = array_values(array_filter($rows, fn ($r) => $r['in_bloc']));
        $outBlocRows = array_values(array_filter($rows, fn ($r) => ! $r['in_bloc']));
        $inLimit = min(count($inBlocRows), (int) ceil($limit / 2));
        $outLimit = $limit - $inLimit;
        $inBlocRows = array_slice($inBlocRows, 0, $inLimit);
        $outBlocRows = array_slice($outBlocRows, 0, $outLimit);
        $rows = array_merge($inBlocRows, $outBlocRows);

        $repoRoot = base_path('..');
        $outPath = $repoRoot . '/' . ltrim($this->option('out'), '/');
        $dir = dirname($outPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outPath, $this->renderMarkdown($rows, $blocName, $blocId, $inBlocRows, $outBlocRows));
        $this->info("Wrote {$outPath} (" . count($rows) . ' rows: ' . count($inBlocRows) . ' in-bloc + ' . count($outBlocRows) . ' out-of-bloc).');
        return self::SUCCESS;
    }

    /**
     * @param  list<array<string,mixed>>  $rows         all rows (for evidence section)
     * @param  list<array<string,mixed>>  $inBlocRows   in-bloc subset
     * @param  list<array<string,mixed>>  $outBlocRows  out-of-bloc subset
     */
    private function renderMarkdown(array $rows, string $blocName, int $blocId, array $inBlocRows, array $outBlocRows): string
    {
        $lines = [];
        $lines[] = '# CI Phase 1 — Top review sample';
        $lines[] = '';
        $lines[] = "Viewer bloc: **{$blocName}** (id `{$blocId}`)  ";
        $lines[] = 'Generated: ' . now()->toIso8601String() . '  ';
        $lines[] = 'Rows: ' . count($rows) . ' (in-bloc: ' . count($inBlocRows) . ' · out-of-bloc: ' . count($outBlocRows) . ')';
        $lines[] = '';
        $lines[] = '> Sample is ranked by Phase 1 flag count (descending), then note count, then';
        $lines[] = '> the pre-Phase-1 review_priority_score. Each row carries its fired signal';
        $lines[] = '> evidence so reviewers can validate the call without re-running the service.';
        $lines[] = '> ';
        $lines[] = '> **In-bloc** = pilot\'s declared alliance is labelled to this viewer bloc.';
        $lines[] = '> Real spy candidates live here. The bloc-relative signals (community';
        $lines[] = '> mismatch, asymmetric counterpart) are diagnostic when they fire on these.';
        $lines[] = '>';
        $lines[] = '> **Out-of-bloc** = pilot is in a labelled hostile alliance. Bloc-relative';
        $lines[] = '> signals fire baseline-true (a hostile-alliance member naturally has a';
        $lines[] = '> hostile graph community), so these rows are signal validation, not';
        $lines[] = '> review queue. Calibration spec needs to either filter them out of the';
        $lines[] = '> band assignment or normalise per-cohort.';
        $lines[] = '>';
        $lines[] = '> Phase 1 thresholds are uncalibrated. Use this sample as input to the';
        $lines[] = '> calibration spec (compare flagged rows against `ci_character_ground_truth`).';
        $lines[] = '';

        $renderTable = function (array $rs, int $offset = 0) use (&$lines): void {
            $lines[] = '| # | Character | Alliance | P1 band | Flags | Notes | Existing band | Existing score | Cohort |';
            $lines[] = '|---|-----------|----------|---------|------:|------:|--------------|---------------:|-------:|';
            foreach ($rs as $i => $r) {
                $idx = $offset + $i + 1;
                $name = str_replace('|', '\|', (string) $r['character_name']);
                $ally = $r['alliance_name'] ? str_replace('|', '\|', (string) $r['alliance_name']) : '—';
                $score = $r['existing_score'] !== null ? number_format((float) $r['existing_score'], 3) : '—';
                $cohort = ($r['cohort_size'] ?? '—') . ' / ' . ($r['cohort_confidence'] ?? '—');
                $existingBand = $r['existing_band'] ?? '—';
                $lines[] = "| {$idx} | [{$name}](/portal/characters/lookup?cid={$r['character_id']}) | {$ally} | "
                    . "**{$r['phase1_band']}** | {$r['flag_count']} | {$r['note_count']} | {$existingBand} | {$score} | {$cohort} |";
            }
        };

        $lines[] = '## In-bloc candidates · ' . count($inBlocRows) . ' rows';
        $lines[] = '';
        if ($inBlocRows === []) {
            $lines[] = '_No in-bloc pilots in the candidate pool. Either the bloc has no member alliances yet labelled friendly, or no member fires Phase 1 signals._';
        } else {
            $renderTable($inBlocRows, 0);
        }
        $lines[] = '';
        $lines[] = '## Out-of-bloc rows · ' . count($outBlocRows) . ' rows';
        $lines[] = '';
        if ($outBlocRows === []) {
            $lines[] = '_None._';
        } else {
            $renderTable($outBlocRows, count($inBlocRows));
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## Per-pilot evidence';
        $lines[] = '';
        foreach ($rows as $i => $r) {
            $idx = $i + 1;
            $tag = $r['in_bloc'] ? 'in-bloc' : 'out-of-bloc';
            $lines[] = "### {$idx}. {$r['character_name']} · `cid={$r['character_id']}` · _{$tag}_";
            $lines[] = '';
            $f = $r['feature'];
            $sampleBits = [];
            $sampleBits[] = 'battles=' . ($f['battles'] ?? '—');
            $sampleBits[] = 'active_days=' . ($f['active_days'] ?? '—');
            $sampleBits[] = 'km(att)=' . ($f['killmails_attacker'] ?? '—');
            $sampleBits[] = 'km(vic)=' . ($f['killmails_victim'] ?? '—');
            $sampleBits[] = 'distinct_corps_lifetime=' . ($f['distinct_corps_all_time'] ?? '—');
            $sampleBits[] = 'short_corps_(1-30d)=' . ($f['corp_tenure_short_count'] ?? '—');
            $sampleBits[] = 'dormancy_max_gap_days=' . ($f['dormancy_max_gap_days'] ?? '—');
            $sampleBits[] = 'ship_loss_count=' . ($f['ship_loss_count'] ?? '—');
            $sampleBits[] = 'pod_survival=' . ($f['pod_survival_rate'] ?? '—');
            $sampleBits[] = 'cheap_loss_rate=' . ($f['cheap_loss_rate'] ?? '—');
            $sampleBits[] = 'battle_only=' . ($f['battle_only_score'] ?? '—');
            $lines[] = '- Sample sizes: `' . implode(' · ', $sampleBits) . '`';
            $lines[] = '- Existing pre-Phase-1: band=`' . ($r['existing_band'] ?? '—')
                . '` score=`' . ($r['existing_score'] ?? '—')
                . '` cohort=`' . ($r['cohort_size'] ?? '—') . '` confidence=`'
                . ($r['cohort_confidence'] ?? '—') . '`';
            $lines[] = '- Phase 1 band: **' . $r['phase1_band'] . '** ('
                . $r['flag_count'] . ' flag, ' . $r['note_count'] . ' note)';
            $lines[] = '';
            if (empty($r['signals'])) {
                $lines[] = '_No Phase 1 signals fired._';
                $lines[] = '';
                continue;
            }
            $lines[] = '| reason | severity | text |';
            $lines[] = '|--------|----------|------|';
            foreach ($r['signals'] as $s) {
                $text = str_replace('|', '\|', (string) ($s['text'] ?? ''));
                $lines[] = '| `' . ($s['key'] ?? '') . '` | ' . ($s['severity'] ?? '') . ' | ' . $text . ' |';
            }
            $lines[] = '';
        }
        return implode("\n", $lines) . "\n";
    }
}
