<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            // Helpers — terse formatters local to this page.
            $isk = function ($v) {
                $v = (float) $v;
                if ($v >= 1e12) return number_format($v / 1e12, 2) . 'T';
                if ($v >= 1e9)  return number_format($v / 1e9,  2) . 'B';
                if ($v >= 1e6)  return number_format($v / 1e6,  2) . 'M';
                if ($v >= 1e3)  return number_format($v / 1e3,  1) . 'k';
                return number_format($v, 0);
            };
            $rank = function (int $i) {
                if ($i === 1) return ['#1', '#fde68a'];
                if ($i === 2) return ['#2', '#cbd5e1'];
                if ($i === 3) return ['#3', '#fdba74'];
                return ['#'.$i, '#7a7a82'];
            };
            $charLink = fn ($cid) => $cid ? "/portal/intelligence/character-lookup?cid={$cid}" : null;
            $alliLink = fn ($aid) => $aid ? "/portal/intelligence/alliance-lookup?aid={$aid}" : null;
        @endphp

        {{-- Window switch --}}
        <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.75rem; flex-wrap:wrap;">
            <span style="font-size:0.65rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.1em;">window</span>
            @foreach ($available_windows as $w)
                <a href="?window={{ $w }}"
                   style="text-decoration:none; padding:4px 10px; font-size:0.78rem; border-radius:5px;
                          background:{{ $w === $window ? 'rgba(134,239,172,0.18)' : 'rgba(255,255,255,0.04)' }};
                          color:{{ $w === $window ? '#86efac' : '#cbd5e1' }};
                          border:1px solid {{ $w === $window ? '#86efac' : 'rgba(255,255,255,0.10)' }};">
                    {{ $w }}
                </a>
            @endforeach
            <span style="margin-left:auto; font-size:0.65rem; color:#7a7a82;">
                {{ $bloc_alliance_count }} blue alliance{{ $bloc_alliance_count === 1 ? '' : 's' }} in bloc · cached 5 min
            </span>
        </div>

        @php
            // Single helper to render a leaderboard card.
            $card = function (string $title, string $subtitle, array $rows, callable $render, callable $rankFn = null) use ($rank) {
                $rankFn = $rankFn ?? $rank;
                ?>
                <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#cbd5e1; margin:0 0 0.15rem; font-weight:600;"><?= htmlspecialchars($title) ?></h3>
                    <p style="font-size:0.6rem; color:#7a7a82; margin:0 0 0.4rem; font-style:italic;"><?= htmlspecialchars($subtitle) ?></p>
                    <?php if (count($rows) === 0): ?>
                        <p style="font-size:0.7rem; color:#7a7a82; font-style:italic; margin:0;">no data in window</p>
                    <?php else: ?>
                        <ol style="list-style:none; padding:0; margin:0; display:grid; gap:0.2rem;">
                            <?php foreach ($rows as $i => $row): ?>
                                <?php [$rankLabel, $rankColor] = $rankFn($i + 1); ?>
                                <li style="display:flex; gap:0.4rem; align-items:center; font-size:0.78rem;
                                           padding:3px 6px; border-radius:4px;
                                           background:<?= $i % 2 === 0 ? 'rgba(255,255,255,0.02)' : 'transparent' ?>;">
                                    <span style="font-size:0.65rem; color:<?= $rankColor ?>; width:24px; flex-shrink:0; font-weight:600;"><?= $rankLabel ?></span>
                                    <?php $render($row); ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
                <?php
            };
        @endphp

        {{-- Combat (your bloc) --}}
        <h2 style="margin:0.5rem 0 0.5rem; font-size:0.8rem; color:#86efac; text-transform:uppercase; letter-spacing:0.12em;">Combat · your bloc</h2>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:0.6rem; margin-bottom:1rem;">
            @php
                $card('Most kills', 'killmails on which the pilot was an attacker', $top_killers, function ($r) use ($charLink) {
                    ?>
                    <a href="<?= $charLink($r->character_id) ?>" style="flex:1; min-width:0; color:#e5e7eb; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars((string) ($r->character_name ?? '#'.$r->character_id)) ?></a>
                    <span style="font-size:0.6rem; color:#7a7a82; flex-shrink:0;"><?= htmlspecialchars((string) ($r->alliance_name ?? '')) ?></span>
                    <span style="font-size:0.78rem; color:#86efac; font-weight:600;"><?= number_format((int) $r->n) ?></span>
                    <?php
                });
                $card('Most losses', 'killmails on which the pilot was the victim', $top_losses, function ($r) use ($charLink) {
                    ?>
                    <a href="<?= $charLink($r->character_id) ?>" style="flex:1; min-width:0; color:#e5e7eb; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars((string) ($r->character_name ?? '#'.$r->character_id)) ?></a>
                    <span style="font-size:0.6rem; color:#7a7a82; flex-shrink:0;"><?= htmlspecialchars((string) ($r->alliance_name ?? '')) ?></span>
                    <span style="font-size:0.78rem; color:#fb7185; font-weight:600;"><?= number_format((int) $r->n) ?></span>
                    <?php
                });
                $card('Most ISK destroyed', 'sum of total_value across kills the pilot landed', $top_isk_destroyed, function ($r) use ($charLink, $isk) {
                    ?>
                    <a href="<?= $charLink($r->character_id) ?>" style="flex:1; min-width:0; color:#e5e7eb; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars((string) ($r->character_name ?? '#'.$r->character_id)) ?></a>
                    <span style="font-size:0.6rem; color:#7a7a82; flex-shrink:0;"><?= htmlspecialchars((string) ($r->alliance_name ?? '')) ?></span>
                    <span style="font-size:0.78rem; color:#86efac; font-weight:600;"><?= $isk($r->isk) ?></span>
                    <?php
                });
                $card('Most ISK lost', 'sum of total_value of the pilot\'s lossmails', $top_isk_lost, function ($r) use ($charLink, $isk) {
                    ?>
                    <a href="<?= $charLink($r->character_id) ?>" style="flex:1; min-width:0; color:#e5e7eb; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars((string) ($r->character_name ?? '#'.$r->character_id)) ?></a>
                    <span style="font-size:0.6rem; color:#7a7a82; flex-shrink:0;"><?= htmlspecialchars((string) ($r->alliance_name ?? '')) ?></span>
                    <span style="font-size:0.78rem; color:#fb7185; font-weight:600;"><?= $isk($r->isk) ?></span>
                    <?php
                });
                $card('Favourite hulls (killing)', 'hulls our pilots fly when landing kills', $fav_hulls_killing, function ($r) {
                    ?>
                    <span style="flex:1; color:#e5e7eb;"><?= htmlspecialchars((string) ($r->hull ?? '?')) ?></span>
                    <span style="font-size:0.78rem; color:#86efac; font-weight:600;"><?= number_format((int) $r->n) ?></span>
                    <?php
                });
                $card('Favourite hulls (lost)', 'hulls our pilots most often lose', $fav_hulls_lost, function ($r) {
                    ?>
                    <span style="flex:1; color:#e5e7eb;"><?= htmlspecialchars((string) ($r->hull ?? '?')) ?></span>
                    <span style="font-size:0.78rem; color:#fb7185; font-weight:600;"><?= number_format((int) $r->n) ?></span>
                    <?php
                });
            @endphp
        </div>

        {{-- Coalition-wide / hostile --}}
        <h2 style="margin:0.5rem 0 0.5rem; font-size:0.8rem; color:#fdba74; text-transform:uppercase; letter-spacing:0.12em;">Coalition-wide · hostile contact</h2>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:0.6rem; margin-bottom:1rem;">
            @php
                $card('Hottest systems', 'kill count + ISK value, all participants', $hot_systems, function ($r) use ($isk) {
                    ?>
                    <span style="flex:1; color:#e5e7eb;"><?= htmlspecialchars((string) ($r->system_name ?? '?')) ?></span>
                    <span style="font-size:0.6rem; color:#7a7a82; flex-shrink:0;"><?= $isk($r->isk) ?></span>
                    <span style="font-size:0.78rem; color:#fdba74; font-weight:600;"><?= number_format((int) $r->n) ?></span>
                    <?php
                });
                $card('Top hostile alliances', 'alliances OUTSIDE your bloc seen as attackers', $hostile_alliances, function ($r) use ($alliLink, $isk) {
                    ?>
                    <a href="<?= $alliLink($r->alliance_id) ?>" style="flex:1; min-width:0; color:#e5e7eb; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars((string) ($r->alliance_name ?? '#'.$r->alliance_id)) ?></a>
                    <span style="font-size:0.6rem; color:#7a7a82; flex-shrink:0;">avg <?= $isk($r->avg_isk) ?></span>
                    <span style="font-size:0.78rem; color:#fb7185; font-weight:600;"><?= number_format((int) $r->n) ?></span>
                    <?php
                });
                $card('Top hostile corps', 'corps OUTSIDE your bloc seen as attackers', $hostile_corps, function ($r) {
                    ?>
                    <span style="flex:1; min-width:0; color:#e5e7eb; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars((string) ($r->corp_name ?? '#'.$r->corporation_id)) ?></span>
                    <span style="font-size:0.6rem; color:#7a7a82; flex-shrink:0;"><?= htmlspecialchars((string) ($r->alliance_name ?? '')) ?></span>
                    <span style="font-size:0.78rem; color:#fb7185; font-weight:600;"><?= number_format((int) $r->n) ?></span>
                    <?php
                });
            @endphp
        </div>

        {{-- Fleet --}}
        <h2 style="margin:0.5rem 0 0.5rem; font-size:0.8rem; color:#7dd3fc; text-transform:uppercase; letter-spacing:0.12em;">Fleet ops · your bloc</h2>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:0.6rem; margin-bottom:1rem;">
            @php
                $card('Most fleet hours', 'sum of fleet_presence_windows.duration_minutes', $fleet_hours, function ($r) {
                    ?>
                    <span style="flex:1; color:#e5e7eb;"><?= htmlspecialchars((string) ($r->character_name ?? '?')) ?></span>
                    <span style="font-size:0.6rem; color:#7a7a82; flex-shrink:0;"><?= number_format((int) $r->sessions) ?> ops · <?= number_format((int) ($r->km ?? 0)) ?> km</span>
                    <span style="font-size:0.78rem; color:#7dd3fc; font-weight:600;"><?= number_format((float) $r->hours, 1) ?>h</span>
                    <?php
                });
                $card('Most kills during fleets', 'killmails landed inside an active fleet window', $fleet_killers, function ($r) {
                    ?>
                    <span style="flex:1; color:#e5e7eb;"><?= htmlspecialchars((string) ($r->character_name ?? '?')) ?></span>
                    <span style="font-size:0.6rem; color:#7a7a82; flex-shrink:0;"><?= number_format((int) $r->sessions) ?> ops</span>
                    <span style="font-size:0.78rem; color:#86efac; font-weight:600;"><?= number_format((int) $r->km) ?></span>
                    <?php
                });
                $card('Most active on comms', 'spoken_messages aggregated across fleet windows', $fleet_talkers, function ($r) {
                    ?>
                    <span style="flex:1; color:#e5e7eb;"><?= htmlspecialchars((string) ($r->character_name ?? '?')) ?></span>
                    <span style="font-size:0.6rem; color:#7a7a82; flex-shrink:0;"><?= number_format((int) $r->sessions) ?> ops</span>
                    <span style="font-size:0.78rem; color:#a5b4fc; font-weight:600;"><?= number_format((int) $r->msgs) ?></span>
                    <?php
                });
            @endphp
        </div>
    @endif
</x-filament-panels::page>
