@php
    use App\Filament\Portal\Pages\Battles;
    use App\Domains\KillmailsBattleTheaters\Services\BattleTheaterSideResolver as S;

    /** @var \App\Domains\KillmailsBattleTheaters\Models\BattleTheater $theater */
    /** @var \App\Domains\KillmailsBattleTheaters\Services\BattleTheaterSideResolution $sides */
    /** @var \Illuminate\Support\Collection $blocs */
    /** @var \Illuminate\Support\Collection $names */
    /** @var \Illuminate\Support\Collection $participants */
    /** @var \Illuminate\Support\Collection $systems */
    /** @var array $side_totals */
    /** @var \Illuminate\Support\Collection $alliance_rows */
    /** @var \App\Domains\UsersCharacters\Models\ViewerContext|null $viewer */
    /** @var array $ships_by_character */
    /** @var \Illuminate\Support\Collection $ship_names */
    /** @var \Illuminate\Support\Collection $ship_group_names */
    /** @var array $kill_feed */
    /** @var array $composition */
    /** @var array $most_valuable_kills */
    /** @var array $top_damage */
    /** @var array $roster_by_side */
    /** @var array $flagship_logos */
    /** @var array $header_stats */

    $sideALabel = $sides->sideABlocId ? ($blocs[$sides->sideABlocId]->display_name ?? 'Side A') : 'Side A';
    $sideBLabel = $sides->sideBBlocId ? ($blocs[$sides->sideBBlocId]->display_name ?? 'Side B') : 'No opposing bloc';

    // EVE image server helpers. CCP CDN, no auth, public cache.
    $charImg     = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/characters/{$id}/portrait?size={$size}" : null;
    $allianceImg = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/alliances/{$id}/logo?size={$size}" : null;
    $corpImg     = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/corporations/{$id}/logo?size={$size}" : null;
    $typeImg     = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/types/{$id}/icon?size={$size}" : null;
    $typeRender  = fn (?int $id, int $size = 128): ?string => $id ? "https://images.evetech.net/types/{$id}/render?size={$size}" : null;

    // Pick the pilot's primary hull (most appearances inside theater).
    $primaryShipOf = function (int $characterId) use ($ships_by_character, $ship_names): array {
        $rows = $ships_by_character[$characterId] ?? [];
        if ($rows === []) return ['type_id' => null, 'name' => '—'];
        arsort($rows);
        $tid = (int) array_key_first($rows);
        return ['type_id' => $tid, 'name' => $ship_names[$tid] ?? ('#' . $tid)];
    };

    $sideBadgeCss = fn (string $s): string => match ($s) {
        'A'     => 'bg-blue-100 text-blue-700 dark:bg-blue-900/60 dark:text-blue-200',
        'B'     => 'bg-red-100 text-red-700 dark:bg-red-900/60 dark:text-red-200',
        default => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    };

    // ISK efficiency split — Side A % / Side B %. Together they sum
    // to 100 whenever any ISK was traded. Drives the big bar under
    // the VS banner.
    $tA = $side_totals[S::SIDE_A];
    $tB = $side_totals[S::SIDE_B];
    $iskTraded = (float) ($tA['isk_killed'] + $tA['isk_lost']);
    $effA = $iskTraded > 0 ? round($tA['isk_killed'] / $iskTraded * 100, 1) : 50.0;
    $effB = $iskTraded > 0 ? round($tB['isk_killed'] / $iskTraded * 100, 1) : 50.0;

    // Security colour for the system label.
    $sysSec = (float) ($theater->primarySystem?->security_status ?? 0.0);
    $sysSecClass = match (true) {
        $sysSec >= 0.5  => 'text-green-600 dark:text-green-400',
        $sysSec >= 0.0  => 'text-amber-600 dark:text-amber-400',
        default         => 'text-red-600 dark:text-red-400',
    };
    $sysSecLabel = number_format($sysSec, 1);

    // Pretty duration HH:MM:SS.
    $dur = $theater->durationSeconds();
    $durFmt = sprintf('%02d:%02d:%02d', intdiv($dur, 3600), intdiv($dur % 3600, 60), $dur % 60);

    $flagA = $flagship_logos[S::SIDE_A] ?? null;
    $flagB = $flagship_logos[S::SIDE_B] ?? null;
@endphp

<x-filament-panels::page>

    {{-- =====================================================
         HEADER — system, region, time, top-line counts
         ===================================================== --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
        <div class="flex flex-wrap items-baseline justify-between gap-4 mb-4">
            <div class="min-w-0">
                <h2 class="text-xl font-bold truncate">
                    Battle in
                    <span class="{{ $sysSecClass }}">{{ $theater->primarySystem?->name ?? '#'.$theater->primary_system_id }}</span>
                </h2>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 flex flex-wrap items-center gap-2">
                    <span class="{{ $sysSecClass }} font-mono">{{ $sysSecLabel }}</span>
                    <span>·</span>
                    <span>{{ $theater->region?->name ?? '—' }}</span>
                    <span>·</span>
                    <span class="font-mono">{{ $theater->start_time?->format('Y-m-d H:i') }} → {{ $theater->end_time?->format('H:i') }} EVE</span>
                    <span>·</span>
                    <span class="font-mono">{{ $durFmt }}</span>
                </div>
            </div>
            <div>
                @if ($theater->locked_at)
                    <span class="px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 text-xs font-medium">Locked</span>
                @else
                    <span class="px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 text-xs font-medium">Live</span>
                @endif
            </div>
        </div>

        {{-- Top-line metric strip --}}
        <dl class="grid grid-cols-2 md:grid-cols-6 gap-4 border-t border-gray-100 dark:border-gray-800 pt-4">
            <div>
                <dt class="text-xs uppercase tracking-wider text-gray-400">ISK destroyed</dt>
                <dd class="text-lg font-mono font-bold text-red-600 dark:text-red-400">{{ Battles::formatIsk((float) $theater->total_isk_lost) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wider text-gray-400">Ships lost</dt>
                <dd class="text-lg font-mono font-bold">{{ number_format($theater->total_kills) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wider text-gray-400">Damage dealt</dt>
                <dd class="text-lg font-mono font-bold">{{ number_format($header_stats['damage']) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wider text-gray-400">Pilots</dt>
                <dd class="text-lg font-mono font-bold">{{ number_format($theater->participant_count) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wider text-gray-400">Corporations</dt>
                <dd class="text-lg font-mono font-bold">{{ number_format($header_stats['corps']) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wider text-gray-400">Alliances</dt>
                <dd class="text-lg font-mono font-bold">{{ number_format($header_stats['alliances']) }}</dd>
            </div>
        </dl>
    </div>

    {{-- =====================================================
         VS BANNER + EFFICIENCY SPLIT
         ===================================================== --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
            {{-- Side A banner --}}
            <div class="flex items-center gap-4 min-w-0">
                @if ($flagA)
                    <img src="{{ $allianceImg($flagA['alliance_id'], 128) }}" alt="" class="w-20 h-20 rounded-lg flex-shrink-0 ring-2 ring-blue-500/40" loading="lazy" />
                @else
                    <div class="w-20 h-20 rounded-lg flex-shrink-0 bg-gradient-to-br from-blue-400/20 to-blue-600/20 ring-2 ring-blue-500/40 flex items-center justify-center">
                        <span class="text-blue-600 dark:text-blue-400 text-2xl font-black">A</span>
                    </div>
                @endif
                <div class="min-w-0">
                    <div class="text-xs font-medium text-blue-500 uppercase tracking-wider">Side A</div>
                    <div class="text-lg md:text-xl font-bold text-blue-600 dark:text-blue-400 truncate">{{ $sideALabel }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $tA['pilots'] }} pilots · {{ $tA['kills'] }} kills · {{ Battles::formatIsk($tA['isk_lost']) }} lost</div>
                </div>
            </div>

            {{-- Versus divider --}}
            <div class="text-center">
                <div class="text-sm font-black tracking-[0.4em] text-gray-300 dark:text-gray-600">VS</div>
                <div class="mt-2 text-xs text-gray-400 dark:text-gray-500">ISK efficiency</div>
                <div class="mt-1 flex items-baseline justify-center gap-3">
                    <span class="text-2xl font-mono font-bold text-blue-600 dark:text-blue-400">{{ $effA }}%</span>
                    <span class="text-gray-300 dark:text-gray-600">—</span>
                    <span class="text-2xl font-mono font-bold text-red-600 dark:text-red-400">{{ $effB }}%</span>
                </div>
            </div>

            {{-- Side B banner --}}
            <div class="flex items-center gap-4 min-w-0 md:flex-row-reverse md:text-right">
                @if ($flagB)
                    <img src="{{ $allianceImg($flagB['alliance_id'], 128) }}" alt="" class="w-20 h-20 rounded-lg flex-shrink-0 ring-2 ring-red-500/40" loading="lazy" />
                @else
                    <div class="w-20 h-20 rounded-lg flex-shrink-0 bg-gradient-to-br from-red-400/20 to-red-600/20 ring-2 ring-red-500/40 flex items-center justify-center">
                        <span class="text-red-600 dark:text-red-400 text-2xl font-black">B</span>
                    </div>
                @endif
                <div class="min-w-0">
                    <div class="text-xs font-medium text-red-500 uppercase tracking-wider">Side B</div>
                    <div class="text-lg md:text-xl font-bold text-red-600 dark:text-red-400 truncate">{{ $sideBLabel }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $tB['pilots'] }} pilots · {{ $tB['kills'] }} kills · {{ Battles::formatIsk($tB['isk_lost']) }} lost</div>
                </div>
            </div>
        </div>

        {{-- Efficiency split bar --}}
        <div class="mt-6 flex h-3 rounded-full overflow-hidden bg-gray-100 dark:bg-gray-800">
            <div class="bg-blue-500" style="width: {{ $effA }}%"></div>
            <div class="bg-red-500" style="width: {{ $effB }}%"></div>
        </div>

        @if ($viewer === null || $viewer->bloc_unresolved)
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                Side labels are inferred from the two largest blocs in this fight.
                <a href="/portal/account-settings" class="underline hover:text-gray-600 dark:hover:text-gray-300">Set your coalition</a> for viewer-relative sides.
            </p>
        @endif
    </div>

    {{-- =====================================================
         SIDE STAT CARDS — kills / losses / ISK
         ===================================================== --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        @foreach ([
            S::SIDE_A => [$sideALabel, 'blue'],
            S::SIDE_B => [$sideBLabel, 'red'],
            S::SIDE_C => ['Other / third parties', 'gray'],
        ] as $sideKey => $meta)
            @php
                [$label, $tone] = $meta;
                $t    = $side_totals[$sideKey];
                $traded = (float) ($t['isk_killed'] + $t['isk_lost']);
                $eff  = $traded > 0 ? round($t['isk_killed'] / $traded * 100, 1) : null;

                $cardBorder  = match ($tone) { 'blue' => 'border-t-4 border-blue-500', 'red' => 'border-t-4 border-red-500', default => 'border-t-4 border-gray-300 dark:border-gray-600' };
                $labelColor  = match ($tone) { 'blue' => 'text-blue-600 dark:text-blue-400', 'red' => 'text-red-600 dark:text-red-400', default => 'text-gray-600 dark:text-gray-400' };
            @endphp
            <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 {{ $cardBorder }} p-5">
                <div class="flex justify-between items-start mb-4">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">Side {{ $sideKey }}</div>
                        <div class="text-base font-bold {{ $labelColor }} truncate mt-0.5">{{ $label }}</div>
                    </div>
                    @if ($eff !== null)
                        <div class="text-right ml-3 flex-shrink-0">
                            <div class="text-xs text-gray-400 dark:text-gray-500">Efficiency</div>
                            <div class="text-sm font-mono font-bold {{ $labelColor }}">{{ $eff }}%</div>
                        </div>
                    @endif
                </div>

                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">Pilots</dt>
                    <dd class="text-right font-mono font-medium">{{ $t['pilots'] }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">Kills</dt>
                    <dd class="text-right font-mono font-medium">{{ $t['kills'] }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">Final blows</dt>
                    <dd class="text-right font-mono font-medium">{{ $t['final_blows'] }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">Losses</dt>
                    <dd class="text-right font-mono font-medium">{{ $t['deaths'] }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">ISK destroyed</dt>
                    <dd class="text-right font-mono font-medium {{ $t['isk_killed'] > 0 ? 'text-green-600 dark:text-green-400' : '' }}">
                        {{ Battles::formatIsk($t['isk_killed']) }}
                    </dd>

                    <dt class="text-gray-500 dark:text-gray-400">ISK lost</dt>
                    <dd class="text-right font-mono font-medium {{ $t['isk_lost'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                        {{ Battles::formatIsk($t['isk_lost']) }}
                    </dd>

                    <dt class="text-gray-500 dark:text-gray-400">Damage done</dt>
                    <dd class="text-right font-mono font-medium">{{ number_format($t['damage_done']) }}</dd>
                </dl>
            </div>
        @endforeach
    </div>

    {{-- =====================================================
         MOST VALUABLE KILLS (per side)
         ===================================================== --}}
    @if (!empty($most_valuable_kills[S::SIDE_A]) || !empty($most_valuable_kills[S::SIDE_B]))
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        @foreach ([
            S::SIDE_A => ['Side A — top kills', 'blue', $sideALabel],
            S::SIDE_B => ['Side B — top kills', 'red', $sideBLabel],
        ] as $sideKey => $meta)
            @php
                [$title, $tone, $labelFor] = $meta;
                $rows = $most_valuable_kills[$sideKey] ?? [];
                $ringColor = $tone === 'blue' ? 'ring-blue-500/30' : 'ring-red-500/30';
                $titleColor = $tone === 'blue' ? 'text-blue-600 dark:text-blue-400' : 'text-red-600 dark:text-red-400';
            @endphp
            <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                <h3 class="text-sm font-semibold mb-1 {{ $titleColor }}">{{ $title }}</h3>
                <div class="text-xs text-gray-400 dark:text-gray-500 mb-3 truncate">Kills by {{ $labelFor }}</div>
                @if ($rows === [])
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">No kills credited to this side.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($rows as $km)
                            <li class="flex items-center gap-3 p-2 rounded-lg bg-gray-50 dark:bg-gray-800/40 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                                <img src="{{ $typeRender($km['ship_type_id'], 64) }}" alt="" class="w-12 h-12 rounded flex-shrink-0 ring-1 {{ $ringColor }}" loading="lazy" />
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium truncate">{{ $km['ship_name'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                        {{ $km['victim_name'] }}
                                        @if ($km['victim_alliance_id'])
                                            · {{ $names[$km['victim_alliance_id']] ?? '#'.$km['victim_alliance_id'] }}
                                        @endif
                                    </div>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <div class="font-mono font-bold text-sm text-amber-600 dark:text-amber-400">{{ Battles::formatIsk($km['total_value']) }}</div>
                                    <div class="text-xs text-gray-400 dark:text-gray-500 font-mono">{{ $km['attacker_count'] }} inv.</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
    @endif

    {{-- =====================================================
         SHIP COMPOSITION per side
         ===================================================== --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        @foreach ([
            S::SIDE_A => ['Side A composition', 'blue', $sideALabel],
            S::SIDE_B => ['Side B composition', 'red', $sideBLabel],
        ] as $sideKey => $meta)
            @php
                [$title, $tone, $labelFor] = $meta;
                $rows = $composition[$sideKey] ?? [];
                $titleColor = $tone === 'blue' ? 'text-blue-600 dark:text-blue-400' : 'text-red-600 dark:text-red-400';
                $barColor = $tone === 'blue' ? 'bg-blue-500' : 'bg-red-500';
                $max = 0;
                foreach ($rows as $r) { if ($r['count'] > $max) $max = $r['count']; }
            @endphp
            <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                <h3 class="text-sm font-semibold mb-1 {{ $titleColor }}">{{ $title }}</h3>
                <div class="text-xs text-gray-400 dark:text-gray-500 mb-3 truncate">{{ $labelFor }}</div>
                @if ($rows === [])
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">No ships flown for this side.</p>
                @else
                    <ul class="space-y-1.5">
                        @foreach ($rows as $r)
                            @php $pct = $max > 0 ? round($r['count'] / $max * 100) : 0; @endphp
                            <li class="flex items-center gap-2 text-sm">
                                <img src="{{ $typeImg($r['sample_type_id'], 32) }}" alt="" class="w-6 h-6 flex-shrink-0 rounded" loading="lazy" />
                                <span class="truncate flex-shrink min-w-0">{{ $r['class'] }}</span>
                                <div class="flex-1 h-1.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                                    <div class="h-1.5 rounded-full {{ $barColor }}" style="width: {{ $pct }}%"></div>
                                </div>
                                <span class="font-mono font-semibold tabular-nums w-8 text-right">{{ $r['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>

    {{-- =====================================================
         TOP DAMAGE DEALERS per side
         ===================================================== --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        @foreach ([
            S::SIDE_A => ['Top damage — Side A', 'blue'],
            S::SIDE_B => ['Top damage — Side B', 'red'],
        ] as $sideKey => $meta)
            @php
                [$title, $tone] = $meta;
                $rows = $top_damage[$sideKey] ?? [];
                $titleColor = $tone === 'blue' ? 'text-blue-600 dark:text-blue-400' : 'text-red-600 dark:text-red-400';
                $barColor = $tone === 'blue' ? 'bg-blue-500' : 'bg-red-500';
                $max = 0;
                foreach ($rows as $r) { if ($r['damage_done'] > $max) $max = $r['damage_done']; }
            @endphp
            <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                <h3 class="text-sm font-semibold mb-3 {{ $titleColor }}">{{ $title }}</h3>
                @if ($rows === [])
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">No damage recorded.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($rows as $r)
                            @php $pct = $max > 0 ? round($r['damage_done'] / $max * 100) : 0; @endphp
                            <li class="flex items-center gap-2 text-sm">
                                <img src="{{ $charImg($r['character_id'], 32) }}" alt="" class="w-7 h-7 rounded flex-shrink-0" loading="lazy" />
                                @if ($r['ship_type_id'])
                                    <img src="{{ $typeImg($r['ship_type_id'], 32) }}" alt="" class="w-6 h-6 flex-shrink-0" loading="lazy" />
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium">{{ $r['character_name'] }}</div>
                                    <div class="text-xs text-gray-400 truncate">
                                        {{ $r['ship_name'] ?? '—' }}
                                        @if ($r['alliance_name'])
                                            · {{ $r['alliance_name'] }}
                                        @endif
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <div class="w-20 h-1.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                                        <div class="h-1.5 rounded-full {{ $barColor }}" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="font-mono font-semibold tabular-nums text-xs w-14 text-right">{{ number_format($r['damage_done']) }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>

    {{-- =====================================================
         ROSTER BY SIDE — alliances per side
         ===================================================== --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        @foreach ([
            S::SIDE_A => ['Roster — Side A', 'blue'],
            S::SIDE_B => ['Roster — Side B', 'red'],
            S::SIDE_C => ['Third parties', 'gray'],
        ] as $sideKey => $meta)
            @php
                [$title, $tone] = $meta;
                $rows = $roster_by_side[$sideKey] ?? collect();
                $titleColor = $tone === 'blue' ? 'text-blue-600 dark:text-blue-400' : ($tone === 'red' ? 'text-red-600 dark:text-red-400' : 'text-gray-600 dark:text-gray-400');
            @endphp
            <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                <h3 class="text-sm font-semibold mb-3 {{ $titleColor }}">
                    {{ $title }}
                    <span class="text-gray-400 dark:text-gray-500 font-normal text-xs ml-1">({{ $rows->count() }})</span>
                </h3>
                @if ($rows->isEmpty())
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">No alliances on this side.</p>
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach ($rows as $row)
                            <li class="flex items-center gap-2 pb-2 border-b border-gray-50 dark:border-gray-800/60 last:border-0">
                                @if ($row['alliance_id'] > 0)
                                    <img src="{{ $allianceImg($row['alliance_id'], 32) }}" alt="" class="w-7 h-7 flex-shrink-0 rounded" loading="lazy" />
                                @else
                                    <div class="w-7 h-7 flex-shrink-0 rounded bg-gray-100 dark:bg-gray-800"></div>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium">{{ $row['alliance_name'] }}</div>
                                    <div class="text-xs text-gray-400 font-mono">
                                        {{ $row['pilots'] }}p · {{ $row['kills'] }}k · {{ $row['deaths'] }}d
                                    </div>
                                </div>
                                <div class="text-right text-xs font-mono flex-shrink-0 {{ $row['isk_lost'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400' }}">
                                    {{ Battles::formatIsk((float) $row['isk_lost']) }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>

    {{-- =====================================================
         KILL FEED — timeline (colored by victim side)
         ===================================================== --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
        <h3 class="text-base font-semibold mb-4">
            Kill feed
            <span class="text-gray-400 dark:text-gray-500 font-normal text-sm ml-1">({{ count($kill_feed) }})</span>
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide w-20">Time</th>
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">Victim</th>
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">Ship</th>
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">Final blow</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Inv.</th>
                        <th class="py-2 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">ISK</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($kill_feed as $km)
                        @php
                            $victimSide  = $km['victim_id'] ? ($sides->sideByCharacterId[(int) $km['victim_id']] ?? 'C') : 'C';
                            $isHighValue = $km['total_value'] >= 1_000_000_000;
                            $isMidValue  = $km['total_value'] >= 100_000_000 && ! $isHighValue;
                            $sideBorderCss = match ($victimSide) {
                                'A'     => 'border-l-4 border-blue-400',
                                'B'     => 'border-l-4 border-red-400',
                                default => 'border-l-4 border-gray-200 dark:border-gray-700',
                            };
                            $rowBg = $isHighValue
                                ? 'bg-amber-50 dark:bg-amber-950/20'
                                : ($isMidValue ? 'bg-amber-50/40 dark:bg-amber-950/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800/40');
                        @endphp
                        <tr class="{{ $rowBg }} transition-colors">
                            <td class="py-2.5 pr-3 font-mono text-xs text-gray-500 whitespace-nowrap {{ $sideBorderCss }} pl-2">
                                {{ \Carbon\Carbon::parse($km['killed_at'])->format('H:i:s') }}
                            </td>
                            <td class="py-2.5 pr-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if ($km['victim_id'])
                                        <img src="{{ $charImg($km['victim_id'], 32) }}" alt="" class="w-7 h-7 rounded flex-shrink-0" loading="lazy" />
                                    @endif
                                    <div class="min-w-0">
                                        <div class="truncate font-medium">{{ $km['victim_name'] }}</div>
                                        @if ($km['victim_alliance_id'])
                                            <div class="flex items-center gap-1 text-xs text-gray-400">
                                                <img src="{{ $allianceImg($km['victim_alliance_id'], 16) }}" alt="" class="w-3 h-3 flex-shrink-0" loading="lazy" />
                                                <span class="truncate">{{ $names[$km['victim_alliance_id']] ?? '#'.$km['victim_alliance_id'] }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="py-2.5 pr-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if ($km['ship_type_id'] > 0)
                                        <img src="{{ $typeImg($km['ship_type_id'], 32) }}" alt="" class="w-7 h-7 flex-shrink-0" loading="lazy" />
                                    @endif
                                    <span class="truncate">{{ $km['ship_name'] }}</span>
                                </div>
                            </td>
                            <td class="py-2.5 pr-3">
                                @if ($km['final_blow_char_id'])
                                    <div class="flex items-center gap-2 min-w-0">
                                        <img src="{{ $charImg($km['final_blow_char_id'], 32) }}" alt="" class="w-7 h-7 rounded flex-shrink-0" loading="lazy" />
                                        <div class="min-w-0">
                                            <div class="truncate">{{ $km['final_blow_name'] }}</div>
                                            @if ($km['final_blow_ship_id'])
                                                <div class="text-xs text-gray-400 truncate">{{ $ship_names[$km['final_blow_ship_id']] ?? '#'.$km['final_blow_ship_id'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ $km['attacker_count'] }}</td>
                            <td class="py-2.5 text-right font-mono {{ $isHighValue ? 'font-bold text-amber-700 dark:text-amber-400' : '' }}">
                                {{ Battles::formatIsk($km['total_value']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- =====================================================
         PILOT TABLE
         ===================================================== --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
        <h3 class="text-base font-semibold mb-4">
            Pilots
            <span class="text-gray-400 dark:text-gray-500 font-normal text-sm ml-1">({{ $participants->count() }})</span>
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">Side</th>
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">Pilot</th>
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">Ship</th>
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">Alliance</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Kills</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">FB</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Dmg done</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Dmg taken</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Deaths</th>
                        <th class="py-2 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">ISK lost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($participants as $p)
                        @php
                            $cid         = (int) $p->character_id;
                            $side        = $sides->sideByCharacterId[$cid] ?? 'C';
                            $charName    = $names[$cid] ?? 'Character #'.$cid;
                            $allianceName = $p->alliance_id ? ($names[(int) $p->alliance_id] ?? '#'.$p->alliance_id) : '—';
                            $ship        = $primaryShipOf($cid);
                        @endphp
                        <tr class="border-b border-gray-50 dark:border-gray-800/60 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                            <td class="py-2.5 pr-3">
                                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold {{ $sideBadgeCss($side) }}">
                                    {{ $side }}
                                </span>
                            </td>
                            <td class="py-2.5 pr-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    <img src="{{ $charImg($cid, 32) }}" alt="" class="w-7 h-7 rounded flex-shrink-0" loading="lazy" />
                                    <span class="truncate font-medium">{{ $charName }}</span>
                                </div>
                            </td>
                            <td class="py-2.5 pr-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if ($ship['type_id'])
                                        <img src="{{ $typeImg($ship['type_id'], 32) }}" alt="" class="w-7 h-7 flex-shrink-0" loading="lazy" />
                                    @endif
                                    <span class="truncate">{{ $ship['name'] }}</span>
                                </div>
                            </td>
                            <td class="py-2.5 pr-3">
                                <div class="flex items-center gap-2 min-w-0 text-gray-500">
                                    @if ($p->alliance_id)
                                        <img src="{{ $allianceImg((int) $p->alliance_id, 20) }}" alt="" class="w-4 h-4 flex-shrink-0" loading="lazy" />
                                    @endif
                                    <span class="truncate">{{ $allianceName }}</span>
                                </div>
                            </td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ $p->kills }}</td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ $p->final_blows }}</td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ number_format($p->damage_done) }}</td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ number_format($p->damage_taken) }}</td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ $p->deaths }}</td>
                            <td class="py-2.5 text-right font-mono {{ $p->isk_lost > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                {{ Battles::formatIsk((float) $p->isk_lost) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- =====================================================
         SYSTEMS
         ===================================================== --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
        <h3 class="text-base font-semibold mb-4">Systems</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">System</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Kills</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">ISK lost</th>
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">First kill</th>
                        <th class="py-2 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">Last kill</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($systems as $s)
                        @php
                            $sysSecS = (float) ($s->solarSystem?->security_status ?? 0.0);
                            $sysSecSCls = match (true) {
                                $sysSecS >= 0.5  => 'text-green-600 dark:text-green-400',
                                $sysSecS >= 0.0  => 'text-amber-600 dark:text-amber-400',
                                default          => 'text-red-600 dark:text-red-400',
                            };
                        @endphp
                        <tr class="border-b border-gray-50 dark:border-gray-800/60 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                            <td class="py-2.5 pr-3 font-medium">
                                <span class="{{ $sysSecSCls }} font-mono text-xs mr-1">{{ number_format($sysSecS, 1) }}</span>
                                {{ $s->solarSystem?->name ?? '#'.$s->solar_system_id }}
                            </td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ $s->kill_count }}</td>
                            <td class="py-2.5 pr-3 text-right font-mono {{ $s->isk_lost > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                {{ Battles::formatIsk((float) $s->isk_lost) }}
                            </td>
                            <td class="py-2.5 pr-3 text-gray-500 font-mono text-xs">{{ $s->first_kill_at?->format('Y-m-d H:i') }}</td>
                            <td class="py-2.5 text-gray-500 font-mono text-xs">{{ $s->last_kill_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</x-filament-panels::page>
