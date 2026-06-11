{{-- Single pilot row: 3-column layout — identity | timeline | outcome.
     Inputs:
       $p                    BattleTheaterParticipant
       $names                id => string
       $allShipsOf           fn(int $cid): list<['type_id','name','count']>
       $deathEventsByChar    cid => list<['ship'=>?{tid,name,km,value}, 'pod'=>?{tid,km,value}, 'at']>
       $fbKmsByChar          cid => list<['km','victim_ship','tid','at']>
       $roleBadge            fn(int $cid): string (HTML)
       $killmailUrl          fn(int $kmId): string
       $formatIsk            fn(float): string
--}}
@php
    $cid = (int) $p->character_id;
    $cName = $names[$cid] ?? 'Character #'.$cid;
    $aId = (int) ($p->alliance_id ?? 0);
    $coId = (int) ($p->corporation_id ?? 0);
    $aName = $aId > 0 ? ($names[$aId] ?? '#'.$aId) : null;
    $coName = $coId > 0 ? ($names[$coId] ?? '#'.$coId) : null;
    $deathEvents = $deathEventsByChar[$cid] ?? [];
    $pFbs = $fbKmsByChar[$cid] ?? [];

    // Build per-ship-type "ship box" model. One box per (pilot, hull):
    //   tid, name, total_count (km appearances), lost_count (destroyed),
    //   pods (list of pod kms attached to this hull's loss events),
    //   first_lost_km (link target for the box if it was lost),
    //   cat (item category; 22/87/18 = roadblock, drives label/group),
    //   is_real (true ship; false = bubble/fighter/drone).
    // Color rule: red box = was lost at least once; green = never lost.
    // Pods chained inline within the red ship's box.
    $allShips = $allShipsOf($cid);
    $shipBoxes = [];
    foreach ($allShips as $sh) {
        $tid = (int) $sh['type_id'];
        // Skip Capsule (type 670) — pod is implicit (every podded
        // pilot has one). When a pod IS destroyed, the loss is
        // chained into the parent ship's red box. Surviving pods
        // would otherwise render as a green "Capsule" box on every
        // pilot card, which is noise.
        if ($tid === 670) continue;
        $shipBoxes[$tid] = [
            'tid' => $tid,
            'name' => (string) $sh['name'],
            'count' => (int) $sh['count'],
            'lost_count' => 0,
            'pods' => [],     // list<['tid','km','value']>
            'first_lost_km' => null,
            'lost_value' => 0.0,
            'cat' => null,
            'is_real' => true,
        ];
    }
    // Cat lookup from raw losses for category labels.
    $_lossCatByTid = [];
    foreach (($lossKmsByChar[$cid] ?? []) as $_le) {
        $_lossCatByTid[(int) $_le['tid']] = (int) $_le['cat'];
    }
    // Cross-reference $deathEvents to mark which ship was lost and
    // attach pod info to its parent. Standalone pod death events
    // don't exist after the new grouping pass — every pod is either
    // attached to a parent ship or dropped.
    foreach ($deathEvents as $ev) {
        if ($ev['ship'] === null) continue;
        $stid = (int) $ev['ship']['tid'];
        if (! isset($shipBoxes[$stid])) {
            $shipBoxes[$stid] = [
                'tid' => $stid, 'name' => (string) $ev['ship']['name'], 'count' => 1,
                'lost_count' => 0, 'pods' => [], 'first_lost_km' => null,
                'lost_value' => 0.0, 'cat' => 0, 'is_real' => true,
            ];
        }
        $shipBoxes[$stid]['lost_count']++;
        $shipBoxes[$stid]['lost_value'] += (float) $ev['ship']['value'];
        $shipBoxes[$stid]['first_lost_km'] = $shipBoxes[$stid]['first_lost_km'] ?? (int) $ev['ship']['km'];
        if ($ev['pod'] !== null) {
            $shipBoxes[$stid]['pods'][] = [
                'tid' => (int) $ev['pod']['tid'],
                'km' => (int) $ev['pod']['km'],
                'value' => (float) $ev['pod']['value'],
            ];
            // Add pod ISK to the parent ship's lost_value so the
            // tooltip / right-column ISK reflects the combined hit.
            $shipBoxes[$stid]['lost_value'] += (float) $ev['pod']['value'];
        }
    }
    // Stamp category + is_real on each box from the per-tid lookup.
    foreach ($shipBoxes as $tid => &$_b) {
        $_b['cat'] = $_lossCatByTid[$tid] ?? 0;
        $_b['is_real'] = ! in_array($_b['cat'], [22, 87, 18], true);
    }
    unset($_b);
    // Order: red boxes (lost) first by lost_value desc, then green
    // (never lost) by km count desc. Roadblocks last.
    uasort($shipBoxes, function ($a, $b) {
        $ar = $a['is_real'] ? 1 : 0; $br = $b['is_real'] ? 1 : 0;
        if ($ar !== $br) return $br - $ar;
        $al = $a['lost_count'] > 0 ? 1 : 0; $bl = $b['lost_count'] > 0 ? 1 : 0;
        if ($al !== $bl) return $bl - $al;
        if ($al) return $b['lost_value'] <=> $a['lost_value'];
        return $b['count'] <=> $a['count'];
    });

    $realDeathCount = 0;
    foreach ($shipBoxes as $b) {
        if ($b['is_real'] && $b['lost_count'] > 0) {
            $realDeathCount += $b['lost_count'];
        }
    }
    $died = $realDeathCount > 0;
@endphp
<div class="bt-pilot-card {{ $died ? 'died' : 'alive' }}">
    {{-- COL 1: identity --}}
    <div class="bt-pilot-id">
        <img src="https://images.evetech.net/characters/{{ $cid }}/portrait?size=64"
             referrerpolicy="no-referrer" loading="lazy" decoding="async" class="bt-pilot-portrait" alt="">
        <div class="bt-pilot-id-text">
            <div class="bt-pilot-name">
                {{ $cName }}
                {!! $roleBadge((int) $cid) !!}
                @if ($died)
                    <span class="bt-died-badge">☠ Died × {{ $realDeathCount }}</span>
                @else
                    <span class="bt-survived-badge">Alive</span>
                @endif
                @if ($pFbs !== []) <span class="bt-fb-badge">FB × {{ count($pFbs) }}</span> @endif
            </div>
            @if ($aId > 0 || $coId > 0)
                <div class="bt-pilot-affil">
                    @if ($coId > 0)
                        <img src="https://images.evetech.net/corporations/{{ $coId }}/logo?size=32"
                             referrerpolicy="no-referrer" loading="lazy" decoding="async" alt="" title="{{ $coName }}">
                    @endif
                    @if ($aId > 0)
                        <img src="https://images.evetech.net/alliances/{{ $aId }}/logo?size=32"
                             referrerpolicy="no-referrer" loading="lazy" decoding="async" alt="" title="{{ $aName }}">
                        <span class="alli-name">{{ $aName }}</span>
                    @elseif ($coId > 0)
                        <span class="corp-name">{{ $coName }}</span>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- COL 2: ship boxes (red = lost with pod chained, green = survived) --}}
    <div class="bt-pilot-timeline">
        @if ($shipBoxes === [])
            <div class="bt-pilot-survived-msg">Survived the battle.</div>
        @else
            @php
                // Group boxes: real ships first (red lost / green alive),
                // then roadblock buckets per category.
                $realBoxes = [];
                $roadblockBoxes = [22 => [], 87 => [], 18 => []];
                $roadblockLabels = [22 => 'Deployables lost', 87 => 'Fighters lost', 18 => 'Drones lost'];
                foreach ($shipBoxes as $b) {
                    if ($b['is_real']) {
                        $realBoxes[] = $b;
                    } else {
                        $roadblockBoxes[$b['cat']][] = $b;
                    }
                }
            @endphp
            @if ($realBoxes !== [])
                <div class="bt-shipboxes">
                    @foreach ($realBoxes as $b)
                        @php
                            $isLost = $b['lost_count'] > 0;
                            $cls = $isLost ? 'lost' : 'alive';
                            $titleParts = [$b['name']];
                            if ($b['count'] > 0) $titleParts[] = "on {$b['count']} km";
                            if ($isLost) $titleParts[] = "{$b['lost_count']} destroyed — ".$formatIsk((float) $b['lost_value']);
                            $title = implode(' · ', $titleParts);
                        @endphp
                        @if ($isLost)
                            <span class="bt-shipbox lost" title="{{ $title }}">
                                <a href="{{ $killmailUrl($b['first_lost_km']) }}" class="bt-shipbox-link" title="{{ $b['name'] }} kill — {{ $formatIsk((float) $b['lost_value']) }}">
                                    <img src="https://images.evetech.net/types/{{ $b['tid'] }}/icon?size=32"
                                         referrerpolicy="no-referrer" loading="lazy" decoding="async" alt="">
                                    <span class="bt-shipbox-name">{{ $b['name'] }}</span>
                                    @if ($b['lost_count'] > 1)
                                        <span class="bt-shipbox-count">×{{ $b['lost_count'] }}</span>
                                    @endif
                                </a>
                                @foreach ($b['pods'] as $pod)
                                    <span class="bt-shipbox-then">→</span>
                                    <a href="{{ $killmailUrl($pod['km']) }}" class="bt-shipbox-podlink" title="Capsule kill — {{ $formatIsk((float) $pod['value']) }}">
                                        <img src="https://images.evetech.net/types/{{ $pod['tid'] }}/icon?size=32"
                                             referrerpolicy="no-referrer" loading="lazy" decoding="async" alt="" class="bt-shipbox-podicon">
                                    </a>
                                @endforeach
                            </span>
                        @else
                            <span class="bt-shipbox alive" title="{{ $title }}">
                                <img src="https://images.evetech.net/types/{{ $b['tid'] }}/icon?size=32"
                                     referrerpolicy="no-referrer" loading="lazy" decoding="async" alt="">
                                <span class="bt-shipbox-name">{{ $b['name'] }}</span>
                                @if ($b['count'] > 1)
                                    <span class="bt-shipbox-count">×{{ $b['count'] }}</span>
                                @endif
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif
            @foreach ($roadblockBoxes as $cat => $boxes)
                @if ($boxes !== [])
                    <div class="bt-roadblock-list">
                        <span class="bt-roadblock-label">{{ $roadblockLabels[$cat] ?? 'Items lost' }}:</span>
                        @foreach ($boxes as $r)
                            <a href="{{ $killmailUrl($r['first_lost_km']) }}" class="bt-roadblock-item" title="{{ $r['lost_count'] }}× {{ $r['name'] }} — {{ $formatIsk((float) $r['lost_value']) }} total">
                                <img src="https://images.evetech.net/types/{{ $r['tid'] }}/icon?size=32"
                                     referrerpolicy="no-referrer" loading="lazy" decoding="async" alt="">
                                <span class="bt-roadblock-count">×{{ $r['lost_count'] }}</span>
                                <span class="bt-roadblock-name">{{ $r['name'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            @endforeach
        @endif
        @if ($pFbs !== [])
            <div class="bt-pilot-fblist">
                <span class="bt-fblist-label">FB on:</span>
                @foreach ($pFbs as $fbk)
                    <a href="{{ $killmailUrl($fbk['km']) }}" class="bt-fblink" title="FB on {{ $fbk['victim_ship'] }} — km {{ $fbk['km'] }}">
                        <img src="https://images.evetech.net/types/{{ $fbk['tid'] }}/icon?size=32"
                             referrerpolicy="no-referrer" loading="lazy" decoding="async" alt="">
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    {{-- COL 3: outcome --}}
    <div class="bt-pilot-outcome">
        @if ($p->kills > 0)
            <div class="bt-outcome-kills">{{ $p->kills }} <span class="bt-outcome-suffix">kills</span></div>
        @endif
        @if ($p->damage_done > 0)
            <div class="bt-outcome-dmg">{{ number_format($p->damage_done) }} <span class="bt-outcome-suffix">dmg</span></div>
        @endif
        @if ((float) $p->isk_lost > 0)
            <div class="bt-outcome-loss">{{ $formatIsk((float) $p->isk_lost) }} <span class="bt-outcome-suffix">lost</span></div>
        @endif
    </div>
</div>
