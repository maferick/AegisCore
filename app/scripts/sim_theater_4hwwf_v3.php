<?php
/**
 * Theater-clustering end-criterion sim v3.
 *
 * Target: 4-HWWF (30000240), last 10 days.
 * Change vs v2: hard gate (rate_cold) + soft score across three axes:
 *   1. Attacker continuity Δ (recent-to-recent jaccard, alliance-weighted)
 *   2. Adjacency Δ (1-jump neighbors, overlap-weighted)
 *   3. Rate slope (regression over last 6 buckets)
 * End if rate_cold AND score_sum ≤ -2 held 10 min.
 *
 * Run: docker compose exec php-fpm php scripts/sim_theater_4hwwf_v3.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const SYS = 30000240;
const DAYS = 10;
const BUCKET_SEC = 300;     // 5-min bucket
const START_RATE = 4;       // kills/5min to open
const STAY_RATE = 1;        // kills/5min to keep alive
const MIN_KILLS = 15;
const COOLDOWN_MIN = 10;    // low-rate run before start evaluating
const HARD_CAP_MIN = 35;    // force-close if cooldown exceeds this
const END_HOLD_MIN = 10;    // score must hold ≤ -2 for this long
const DT_START = '10:55';   // EVE downtime
const DT_END   = '11:30';

// Load kills.
$since = (new DateTimeImmutable())->modify('-' . DAYS . ' days')->format('Y-m-d H:i:s');
$kills = DB::select(<<<SQL
    SELECT k.killmail_id, UNIX_TIMESTAMP(k.killed_at) AS ts, k.solar_system_id
      FROM killmails k
     WHERE k.solar_system_id = ?
       AND k.killed_at >= ?
     ORDER BY k.killed_at
SQL, [SYS, $since]);

// Adjacent kills (1-jump gate neighbors).
$neighbors = array_map(fn($r) => (int)$r->destination_system_id,
    DB::select('SELECT destination_system_id FROM ref_stargates WHERE solar_system_id = ?', [SYS]));
$neighbors = array_values(array_unique(array_filter($neighbors)));
$adjKills = [];
if ($neighbors !== []) {
    $ph = implode(',', array_fill(0, count($neighbors), '?'));
    $adjRows = DB::select(<<<SQL
        SELECT k.killmail_id, UNIX_TIMESTAMP(k.killed_at) AS ts, k.solar_system_id
          FROM killmails k
         WHERE k.solar_system_id IN ($ph)
           AND k.killed_at >= ?
         ORDER BY k.killed_at
    SQL, array_merge($neighbors, [$since]));
    foreach ($adjRows as $r) $adjKills[] = ['ts' => (int)$r->ts, 'km' => (int)$r->killmail_id, 'sys' => (int)$r->solar_system_id];
}

// Preload attackers for target-system kills + adjacent kills (batched).
$allKmIds = array_merge(
    array_map(fn($r) => (int)$r->killmail_id, $kills),
    array_map(fn($r) => $r['km'], $adjKills)
);
$attackers = [];  // km => list of ['char'=>, 'corp'=>, 'alli'=>]
if ($allKmIds !== []) {
    foreach (array_chunk($allKmIds, 1000) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $rows = DB::select(<<<SQL
            SELECT killmail_id, character_id, corporation_id, alliance_id
              FROM killmail_attackers
             WHERE killmail_id IN ($ph)
        SQL, $chunk);
        foreach ($rows as $r) {
            $attackers[(int)$r->killmail_id][] = [
                'char' => $r->character_id ? (int)$r->character_id : null,
                'corp' => $r->corporation_id ? (int)$r->corporation_id : null,
                'alli' => $r->alliance_id ? (int)$r->alliance_id : null,
            ];
        }
    }
}

// Bucket target-system kills by 5-min.
$buckets = [];  // epoch bucket-start => list of km ids
foreach ($kills as $k) {
    $bs = (int)(floor((int)$k->ts / BUCKET_SEC) * BUCKET_SEC);
    $buckets[$bs][] = (int)$k->killmail_id;
}
ksort($buckets);

// Helpers.
$isDowntime = function(int $ts): bool {
    $hhmm = gmdate('H:i', $ts);
    return $hhmm >= DT_START && $hhmm < DT_END;
};

$attackerSet = function(array $kmIds, string $field): array {
    global $attackers;
    $s = [];
    foreach ($kmIds as $km) {
        foreach ($attackers[$km] ?? [] as $a) {
            if ($a[$field] !== null) $s[$a[$field]] = true;
        }
    }
    return $s;
};

$jaccardWeighted = function(array $aAlli, array $bAlli, array $aCorp, array $bCorp): float {
    $interA = count(array_intersect_key($aAlli, $bAlli));
    $unionA = count($aAlli + $bAlli);
    $interC = count(array_intersect_key($aCorp, $bCorp));
    $unionC = count($aCorp + $bCorp);
    $num = 2 * $interA + $interC;
    $den = 2 * max($unionA, 1) + max($unionC, 1);
    return $den > 0 ? $num / $den : 0.0;
};

$kmsInWindow = function(int $lo, int $hi) use ($kills): array {
    $out = [];
    foreach ($kills as $k) {
        if ((int)$k->ts >= $lo && (int)$k->ts < $hi) $out[] = (int)$k->killmail_id;
    }
    return $out;
};

$adjKmsInWindow = function(int $lo, int $hi) use ($adjKills): array {
    $out = [];
    foreach ($adjKills as $a) {
        if ($a['ts'] >= $lo && $a['ts'] < $hi) $out[] = $a['km'];
    }
    return $out;
};

// Build bucket time range.
$minTs = null; $maxTs = null;
foreach ($kills as $k) {
    $ts = (int)$k->ts;
    if ($minTs === null || $ts < $minTs) $minTs = $ts;
    if ($maxTs === null || $ts > $maxTs) $maxTs = $ts;
}
if ($minTs === null) { echo "No kills in window.\n"; exit; }
$startB = (int)(floor($minTs / BUCKET_SEC) * BUCKET_SEC);
$endB   = (int)(floor($maxTs / BUCKET_SEC) * BUCKET_SEC) + BUCKET_SEC;

// Scan buckets.
$theaters = [];
$open = null;  // ['start' => ts, 'kms' => [], 'lastRateTs' => ts, 'endEvalStart' => null, 'endHoldStart' => null]

for ($t = $startB; $t < $endB; $t += BUCKET_SEC) {
    if ($isDowntime($t)) continue;
    $nKills = count($buckets[$t] ?? []);

    if ($open === null) {
        if ($nKills >= START_RATE) {
            $open = ['start' => $t, 'kms' => $buckets[$t], 'lastActiveTs' => $t, 'coolStart' => null, 'endHoldStart' => null];
        }
        continue;
    }

    // Open theater — accumulate.
    if ($nKills >= STAY_RATE) {
        $open['kms'] = array_merge($open['kms'], $buckets[$t] ?? []);
        $open['lastActiveTs'] = $t;
        $open['coolStart'] = null;
        $open['endHoldStart'] = null;
        continue;
    }

    // Low-rate bucket.
    if ($open['coolStart'] === null) $open['coolStart'] = $t;
    $coolSec = $t + BUCKET_SEC - $open['coolStart'];
    // Hard cap: if cooldown too long, force-close regardless.
    if ($coolSec >= HARD_CAP_MIN * 60) {
        $open['endTs'] = $open['lastActiveTs'] + BUCKET_SEC;
        $open['closeReason'] = 'hard-cap cooldown ' . ($coolSec / 60) . 'm';
        $theaters[] = $open;
        $open = null;
        continue;
    }
    if ($coolSec < COOLDOWN_MIN * 60) continue;

    // Compute three axes.
    // 1. Rate cold: last 30-min sum ≤ 2 kills.
    $lastWin = 0;
    for ($u = $t - 25 * 60; $u <= $t; $u += BUCKET_SEC) $lastWin += count($buckets[$u] ?? []);
    $rateCold = $lastWin <= 2;
    if (!$rateCold) continue;

    // 2. Attacker continuity Δ: last 15-min-of-activity frozen at coolStart, vs adj window now.
    // prev anchor = [lastActiveTs - 15m, lastActiveTs + bucket).
    $anchor = $open['lastActiveTs'] + BUCKET_SEC;
    $prevKms = $kmsInWindow($anchor - 15 * 60, $anchor);
    $nowKms  = $kmsInWindow($t - 15 * 60, $t + BUCKET_SEC);
    $nowAlli = $attackerSet($nowKms, 'alli'); $prevAlli = $attackerSet($prevKms, 'alli');
    $nowCorp = $attackerSet($nowKms, 'corp'); $prevCorp = $attackerSet($prevKms, 'corp');
    // Fold adjacent-kill attackers into "now" side — pilots still fighting nearby count as continuing crew.
    $adjRecent = $adjKmsInWindow($t - 15 * 60, $t + BUCKET_SEC);
    foreach ($adjRecent as $km) {
        foreach ($attackers[$km] ?? [] as $a) {
            if ($a['alli']) $nowAlli[$a['alli']] = true;
            if ($a['corp']) $nowCorp[$a['corp']] = true;
        }
    }
    $jac = $jaccardWeighted($nowAlli, $prevAlli, $nowCorp, $prevCorp);
    $contAxis = $jac < 0.2 ? -1 : ($jac < 0.5 ? 0 : +1);

    // 3. Adjacency Δ: 1-jump kills in last 15 min with attacker overlap vs prev window.
    $overlapCount = 0;
    foreach ($adjRecent as $km) {
        $kmAlli = [];
        foreach ($attackers[$km] ?? [] as $a) if ($a['alli']) $kmAlli[$a['alli']] = true;
        if ($prevAlli !== [] && count(array_intersect_key($kmAlli, $prevAlli)) >= max(1, (int)(0.3 * count($prevAlli)))) {
            $overlapCount++;
        }
    }
    $adjCount = count($adjRecent);
    if ($adjCount >= 4 && $overlapCount >= 2) $adjAxis = +1;       // spillover: keep open
    elseif ($adjCount < 4) $adjAxis = -1;                          // no adjacent, dead
    else $adjAxis = 0;                                             // activity but different crew

    // 4. Rate slope (regression, last 6 buckets).
    $ys = []; $xs = [];
    for ($i = 0; $i < 6; $i++) {
        $ys[] = count($buckets[$t - $i * BUCKET_SEC] ?? []);
        $xs[] = 5 - $i;
    }
    $n = count($xs);
    $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
    $num = 0.0; $den = 0.0;
    for ($i = 0; $i < $n; $i++) { $num += ($xs[$i] - $mx) * ($ys[$i] - $my); $den += ($xs[$i] - $mx) ** 2; }
    $slope = $den > 0 ? $num / $den : 0.0;
    $slopeAxis = $slope < -0.5 ? -1 : ($slope > 0.5 ? +1 : 0);

    $score = $contAxis + $adjAxis + $slopeAxis;

    // Anti-flap: score must hold ≤ -2 for END_HOLD_MIN.
    if ($score <= -2) {
        if ($open['endHoldStart'] === null) $open['endHoldStart'] = $t;
        if ($t - $open['endHoldStart'] >= END_HOLD_MIN * 60) {
            // Close.
            $open['endTs'] = $open['lastActiveTs'] + BUCKET_SEC;
            $open['closeReason'] = "score=$score cont=$contAxis adj=$adjAxis slope=$slopeAxis jac=" . sprintf('%.2f', $jac) . " adjN=$adjCount overlap=$overlapCount";
            $theaters[] = $open;
            $open = null;
        }
    } else {
        // Score climbed back — adjacency spillover or partial continuity. Keep open.
        // Extend lastActive if adj spillover (so we don't immediately re-evaluate).
        if ($adjAxis === +1) $open['lastActiveTs'] = $t;
        $open['endHoldStart'] = null;
    }

    // Spillover merge: if adj +1 + some target-system activity, absorb as ongoing.
    if ($adjAxis === +1 && $nKills > 0) {
        $open['kms'] = array_merge($open['kms'], $buckets[$t] ?? []);
    }
}
if ($open !== null) {
    $open['endTs'] = $open['lastActiveTs'] + BUCKET_SEC;
    $open['closeReason'] = 'end-of-window';
    $theaters[] = $open;
}

// Filter + report.
$kept = array_values(array_filter($theaters, fn($th) => count($th['kms']) >= MIN_KILLS));
printf("Detected: %d candidates, %d ≥ MIN_KILLS=%d\n\n", count($theaters), count($kept), MIN_KILLS);
printf("%-20s  %-20s  %6s  %6s  %7s  %s\n", 'start (UTC)', 'end (UTC)', 'dur_m', 'kms', 'pilots', 'close');
printf("%s\n", str_repeat('-', 120));
foreach ($kept as $th) {
    $dur = (int)(($th['endTs'] - $th['start']) / 60);
    $pilots = [];
    foreach ($th['kms'] as $km) foreach ($attackers[$km] ?? [] as $a) if ($a['char']) $pilots[$a['char']] = true;
    printf("%-20s  %-20s  %6d  %6d  %7d  %s\n",
        gmdate('Y-m-d H:i', $th['start']),
        gmdate('Y-m-d H:i', $th['endTs']),
        $dur,
        count($th['kms']),
        count($pilots),
        $th['closeReason'],
    );
}
