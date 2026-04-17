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

    $sideBloc = fn (?int $blocId): ?string => $blocId ? ($blocs[$blocId]->display_name ?? null) : null;
    $blocA = $sideBloc($sides->sideABlocId);
    $blocB = $sideBloc($sides->sideBBlocId);
    $flagA = $flagship_logos[S::SIDE_A] ?? null;
    $flagB = $flagship_logos[S::SIDE_B] ?? null;
    $sideAHeadline = $flagA['alliance_name'] ?? $blocA ?? 'Side A';
    $sideBHeadline = $flagB['alliance_name'] ?? $blocB ?? 'No opposing side';

    $charImg     = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/characters/{$id}/portrait?size={$size}" : null;
    $allianceImg = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/alliances/{$id}/logo?size={$size}" : null;
    $typeImg     = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/types/{$id}/icon?size={$size}" : null;
    $typeRender  = fn (?int $id, int $size = 128): ?string => $id ? "https://images.evetech.net/types/{$id}/render?size={$size}" : null;

    $primaryShipOf = function (int $characterId) use ($ships_by_character, $ship_names): array {
        $rows = $ships_by_character[$characterId] ?? [];
        if ($rows === []) return ['type_id' => null, 'name' => '—'];
        arsort($rows);
        $tid = (int) array_key_first($rows);
        return ['type_id' => $tid, 'name' => $ship_names[$tid] ?? ('#' . $tid)];
    };

    $sideColor = fn (string $s): string => match ($s) {
        'A' => 'info', 'B' => 'danger', default => 'gray',
    };
    $sideTone = fn (string $s): string => match ($s) {
        'A' => 'blue', 'B' => 'red', default => 'gray',
    };

    $tA = $side_totals[S::SIDE_A];
    $tB = $side_totals[S::SIDE_B];
    $effA = ($tA['isk_killed'] + $tA['isk_lost']) > 0 ? round($tA['isk_killed'] / ($tA['isk_killed'] + $tA['isk_lost']) * 100, 1) : 50.0;
    $effB = ($tB['isk_killed'] + $tB['isk_lost']) > 0 ? round($tB['isk_killed'] / ($tB['isk_killed'] + $tB['isk_lost']) * 100, 1) : 50.0;
    $abDestroyed = (float) ($tA['isk_killed'] + $tB['isk_killed']);
    $barA = $abDestroyed > 0 ? round($tA['isk_killed'] / $abDestroyed * 100, 1) : 50.0;
    $barB = 100 - $barA;

    $sysSec = (float) ($theater->primarySystem?->security_status ?? 0.0);
    $sysSecClass = match (true) {
        $sysSec >= 0.5  => 'text-green-600 dark:text-green-400',
        $sysSec >= 0.0  => 'text-amber-600 dark:text-amber-400',
        default         => 'text-red-600 dark:text-red-400',
    };
    $sysSecLabel = number_format($sysSec, 1);

    $dur = $theater->durationSeconds();
    $durFmt = sprintf('%02d:%02d:%02d', intdiv($dur, 3600), intdiv($dur % 3600, 60), $dur % 60);

    // Shared table class set — tailwind utilities that mimic the look
    // of Filament's own table rows (divide-y, hover shading, compact
    // cell padding).
    $tblWrap = 'overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800';
    $tblBase = 'w-full text-sm divide-y divide-gray-200 dark:divide-gray-800';
    $thBase  = 'px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide bg-gray-50 dark:bg-gray-800/60';
    $tdBase  = 'px-3 py-2 align-middle';
    $trHover = 'hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors';
@endphp

<x-filament-panels::page>

    {{-- =====================================================
         HEADER
         ===================================================== --}}
    <x-filament::section icon="heroicon-o-information-circle">
        <x-slot name="heading">
            <span class="{{ $sysSecClass }} font-mono mr-1">{{ $sysSecLabel }}</span>
            {{ $theater->primarySystem?->name ?? '#'.$theater->primary_system_id }}
        </x-slot>
        <x-slot name="description">
            {{ $theater->region?->name ?? '—' }} ·
            {{ $theater->start_time?->format('Y-m-d H:i') }} → {{ $theater->end_time?->format('H:i') }} EVE ·
            {{ $durFmt }}
        </x-slot>
        <x-slot name="headerEnd">
            @if ($theater->locked_at)
                <x-filament::badge color="gray">Locked</x-filament::badge>
            @else
                <x-filament::badge color="success">Live</x-filament::badge>
            @endif
        </x-slot>

        <dl class="grid grid-cols-2 md:grid-cols-6 gap-4">
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
    </x-filament::section>

    {{-- =====================================================
         VS BANNER (card — not tabular)
         ===================================================== --}}
    <x-filament::section>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
            <div class="flex items-center gap-4 min-w-0">
                @if ($flagA)
                    <x-filament::avatar src="{{ $allianceImg($flagA['alliance_id'], 128) }}" :circular="false" class="w-20 h-20 ring-2 ring-blue-500/40" />
                @else
                    <div class="w-20 h-20 rounded-lg flex-shrink-0 bg-gradient-to-br from-blue-400/20 to-blue-600/20 ring-2 ring-blue-500/40 flex items-center justify-center">
                        <span class="text-blue-600 dark:text-blue-400 text-2xl font-black">A</span>
                    </div>
                @endif
                <div class="min-w-0">
                    <x-filament::badge color="info" size="xs">Side A</x-filament::badge>
                    <div class="text-lg md:text-xl font-bold text-blue-600 dark:text-blue-400 truncate mt-1">{{ $sideAHeadline }}</div>
                    @if ($blocA && $blocA !== $sideAHeadline)
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $blocA }} bloc</div>
                    @endif
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 font-mono">{{ $tA['pilots'] }}p · {{ $tA['kills'] }}k · {{ Battles::formatIsk($tA['isk_lost']) }} lost</div>
                </div>
            </div>

            <div class="text-center">
                <div class="text-sm font-black tracking-[0.4em] text-gray-300 dark:text-gray-600">VS</div>
                <div class="mt-2 text-xs text-gray-400 dark:text-gray-500">ISK efficiency</div>
                <div class="mt-1 flex items-baseline justify-center gap-3">
                    <span class="text-2xl font-mono font-bold text-blue-600 dark:text-blue-400">{{ $effA }}%</span>
                    <span class="text-gray-300 dark:text-gray-600">—</span>
                    <span class="text-2xl font-mono font-bold text-red-600 dark:text-red-400">{{ $effB }}%</span>
                </div>
            </div>

            <div class="flex items-center gap-4 min-w-0 md:flex-row-reverse md:text-right">
                @if ($flagB)
                    <x-filament::avatar src="{{ $allianceImg($flagB['alliance_id'], 128) }}" :circular="false" class="w-20 h-20 ring-2 ring-red-500/40" />
                @else
                    <div class="w-20 h-20 rounded-lg flex-shrink-0 bg-gradient-to-br from-red-400/20 to-red-600/20 ring-2 ring-red-500/40 flex items-center justify-center">
                        <span class="text-red-600 dark:text-red-400 text-2xl font-black">B</span>
                    </div>
                @endif
                <div class="min-w-0">
                    <x-filament::badge color="danger" size="xs">Side B</x-filament::badge>
                    <div class="text-lg md:text-xl font-bold text-red-600 dark:text-red-400 truncate mt-1">{{ $sideBHeadline }}</div>
                    @if ($blocB && $blocB !== $sideBHeadline)
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $blocB }} bloc</div>
                    @endif
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 font-mono">{{ $tB['pilots'] }}p · {{ $tB['kills'] }}k · {{ Battles::formatIsk($tB['isk_lost']) }} lost</div>
                </div>
            </div>
        </div>

        <div class="mt-6 flex h-3 rounded-full overflow-hidden bg-gray-100 dark:bg-gray-800">
            <div class="bg-blue-500" style="width: {{ $barA }}%"></div>
            <div class="bg-red-500" style="width: {{ $barB }}%"></div>
        </div>

        @if ($viewer === null || $viewer->bloc_unresolved)
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                Side labels are inferred from the two largest blocs in this fight.
                <a href="/portal/account-settings" class="underline hover:text-gray-600 dark:hover:text-gray-300">Set your coalition</a> for viewer-relative sides.
            </p>
        @endif
    </x-filament::section>

    {{-- =====================================================
         SIDE SUMMARY TABLE
         ===================================================== --}}
    <x-filament::section icon="heroicon-o-scale">
        <x-slot name="heading">Side summary</x-slot>
        <x-slot name="description">Pilots, kills, losses, ISK, damage per side</x-slot>

        <div class="{{ $tblWrap }}">
            <table class="{{ $tblBase }}">
                <thead>
                    <tr>
                        <th class="{{ $thBase }}">Side</th>
                        <th class="{{ $thBase }}">Label</th>
                        <th class="{{ $thBase }} text-right">Pilots</th>
                        <th class="{{ $thBase }} text-right">Kills</th>
                        <th class="{{ $thBase }} text-right">FB</th>
                        <th class="{{ $thBase }} text-right">Losses</th>
                        <th class="{{ $thBase }} text-right">ISK destroyed</th>
                        <th class="{{ $thBase }} text-right">ISK lost</th>
                        <th class="{{ $thBase }} text-right">Dmg done</th>
                        <th class="{{ $thBase }} text-right">Eff %</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ([
                        S::SIDE_A => [$sideAHeadline, 'info', $blocA],
                        S::SIDE_B => [$sideBHeadline, 'danger', $blocB],
                        S::SIDE_C => ['Other / third parties', 'gray', null],
                    ] as $sideKey => $meta)
                        @php
                            [$label, $color, $sub] = $meta;
                            $t = $side_totals[$sideKey];
                            $traded = (float) ($t['isk_killed'] + $t['isk_lost']);
                            $eff = $traded > 0 ? round($t['isk_killed'] / $traded * 100, 1) : null;
                        @endphp
                        <tr class="{{ $trHover }}">
                            <td class="{{ $tdBase }}"><x-filament::badge :color="$color" size="xs">{{ $sideKey }}</x-filament::badge></td>
                            <td class="{{ $tdBase }}">
                                <div class="font-medium">{{ $label }}</div>
                                @if ($sub && $sub !== $label)
                                    <div class="text-xs text-gray-400">{{ $sub }} bloc</div>
                                @endif
                            </td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $t['pilots'] }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $t['kills'] }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $t['final_blows'] }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $t['deaths'] }}</td>
                            <td class="{{ $tdBase }} text-right font-mono {{ $t['isk_killed'] > 0 ? 'text-green-600 dark:text-green-400' : '' }}">{{ Battles::formatIsk($t['isk_killed']) }}</td>
                            <td class="{{ $tdBase }} text-right font-mono {{ $t['isk_lost'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ Battles::formatIsk($t['isk_lost']) }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ number_format($t['damage_done']) }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $eff !== null ? $eff.'%' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- =====================================================
         MOST VALUABLE KILLS per side — card showcase
         ===================================================== --}}
    @if (!empty($most_valuable_kills[S::SIDE_A]) || !empty($most_valuable_kills[S::SIDE_B]))
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ([
            S::SIDE_A => ['Top kills — Side A', 'info', 'blue', $sideAHeadline],
            S::SIDE_B => ['Top kills — Side B', 'danger', 'red', $sideBHeadline],
        ] as $sideKey => $meta)
            @php
                [$title, $color, $tone, $labelFor] = $meta;
                $rows = $most_valuable_kills[$sideKey] ?? [];
            @endphp
            <x-filament::section icon="heroicon-o-trophy" :icon-color="$color">
                <x-slot name="heading">{{ $title }}</x-slot>
                <x-slot name="description">Kills by {{ $labelFor }}</x-slot>
                @if ($rows === [])
                    <p class="text-sm text-gray-400 italic">No kills credited to this side.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($rows as $km)
                            <li class="flex items-center gap-3 p-2 rounded-lg bg-gray-50 dark:bg-gray-800/40">
                                <x-filament::avatar src="{{ $typeRender($km['ship_type_id'], 64) }}" :circular="false" class="w-12 h-12 ring-1 ring-{{ $tone }}-500/30" />
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
                                    <div class="text-xs text-gray-400 font-mono">{{ $km['attacker_count'] }} inv.</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endforeach
    </div>
    @endif

    {{-- =====================================================
         SHIP COMPOSITION — one table per side
         ===================================================== --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ([
            S::SIDE_A => ['Composition — Side A', 'info', 'blue', $sideAHeadline],
            S::SIDE_B => ['Composition — Side B', 'danger', 'red', $sideBHeadline],
        ] as $sideKey => $meta)
            @php
                [$title, $color, $tone, $labelFor] = $meta;
                $rows = $composition[$sideKey] ?? [];
                $max = 0;
                foreach ($rows as $r) { if ($r['count'] > $max) $max = $r['count']; }
            @endphp
            <x-filament::section icon="heroicon-o-squares-2x2" :icon-color="$color">
                <x-slot name="heading">{{ $title }}</x-slot>
                <x-slot name="description">{{ $labelFor }}</x-slot>
                @if ($rows === [])
                    <p class="text-sm text-gray-400 italic">No ships flown for this side.</p>
                @else
                    <div class="{{ $tblWrap }}">
                        <table class="{{ $tblBase }}">
                            <thead>
                                <tr>
                                    <th class="{{ $thBase }}">Class</th>
                                    <th class="{{ $thBase }} w-full">Share</th>
                                    <th class="{{ $thBase }} text-right">Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    @php $pct = $max > 0 ? round($r['count'] / $max * 100) : 0; @endphp
                                    <tr class="{{ $trHover }}">
                                        <td class="{{ $tdBase }}">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <x-filament::avatar src="{{ $typeImg($r['sample_type_id'], 32) }}" :circular="false" size="sm" />
                                                <span class="truncate font-medium">{{ $r['class'] }}</span>
                                            </div>
                                        </td>
                                        <td class="{{ $tdBase }}">
                                            <div class="h-2 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                                                <div class="h-2 rounded-full bg-{{ $tone }}-500" style="width: {{ $pct }}%"></div>
                                            </div>
                                        </td>
                                        <td class="{{ $tdBase }} text-right font-mono font-semibold">{{ $r['count'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    {{-- =====================================================
         TOP DAMAGE — table per side
         ===================================================== --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ([
            S::SIDE_A => ['Top damage — Side A', 'info', 'blue'],
            S::SIDE_B => ['Top damage — Side B', 'danger', 'red'],
        ] as $sideKey => $meta)
            @php
                [$title, $color, $tone] = $meta;
                $rows = $top_damage[$sideKey] ?? [];
            @endphp
            <x-filament::section icon="heroicon-o-bolt" :icon-color="$color">
                <x-slot name="heading">{{ $title }}</x-slot>
                @if ($rows === [])
                    <p class="text-sm text-gray-400 italic">No damage recorded.</p>
                @else
                    <div class="{{ $tblWrap }}">
                        <table class="{{ $tblBase }}">
                            <thead>
                                <tr>
                                    <th class="{{ $thBase }}">Pilot</th>
                                    <th class="{{ $thBase }}">Ship</th>
                                    <th class="{{ $thBase }}">Alliance</th>
                                    <th class="{{ $thBase }} text-right">Kills</th>
                                    <th class="{{ $thBase }} text-right">FB</th>
                                    <th class="{{ $thBase }} text-right">Dmg</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr class="{{ $trHover }}">
                                        <td class="{{ $tdBase }}">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <x-filament::avatar src="{{ $charImg($r['character_id'], 32) }}" size="sm" />
                                                <span class="truncate font-medium">{{ $r['character_name'] }}</span>
                                            </div>
                                        </td>
                                        <td class="{{ $tdBase }}">
                                            <div class="flex items-center gap-2 min-w-0">
                                                @if ($r['ship_type_id'])
                                                    <x-filament::avatar src="{{ $typeImg($r['ship_type_id'], 32) }}" :circular="false" size="sm" />
                                                @endif
                                                <span class="truncate">{{ $r['ship_name'] ?? '—' }}</span>
                                            </div>
                                        </td>
                                        <td class="{{ $tdBase }} text-gray-500 truncate max-w-[16ch]">{{ $r['alliance_name'] ?? '—' }}</td>
                                        <td class="{{ $tdBase }} text-right font-mono">{{ $r['kills'] }}</td>
                                        <td class="{{ $tdBase }} text-right font-mono">{{ $r['final_blows'] }}</td>
                                        <td class="{{ $tdBase }} text-right font-mono font-semibold">{{ number_format($r['damage_done']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    {{-- =====================================================
         ALLIANCE ROSTER — one big sortable-ish table
         ===================================================== --}}
    <x-filament::section icon="heroicon-o-user-group">
        <x-slot name="heading">Alliances</x-slot>
        <x-slot name="description">{{ $alliance_rows->count() }} alliance(s) across all sides, sorted by ISK lost</x-slot>

        <div class="{{ $tblWrap }}">
            <table class="{{ $tblBase }}">
                <thead>
                    <tr>
                        <th class="{{ $thBase }}">Alliance</th>
                        <th class="{{ $thBase }}">Side</th>
                        <th class="{{ $thBase }} text-right">Pilots</th>
                        <th class="{{ $thBase }} text-right">Kills</th>
                        <th class="{{ $thBase }} text-right">Deaths</th>
                        <th class="{{ $thBase }} text-right">Dmg done</th>
                        <th class="{{ $thBase }} text-right">Dmg taken</th>
                        <th class="{{ $thBase }} text-right">ISK lost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($alliance_rows as $row)
                        <tr class="{{ $trHover }}">
                            <td class="{{ $tdBase }}">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if ($row['alliance_id'] > 0)
                                        <x-filament::avatar src="{{ $allianceImg($row['alliance_id'], 32) }}" :circular="false" size="sm" />
                                    @else
                                        <div class="w-7 h-7 flex-shrink-0 rounded bg-gray-100 dark:bg-gray-800"></div>
                                    @endif
                                    <span class="truncate font-medium">{{ $row['alliance_name'] }}</span>
                                </div>
                            </td>
                            <td class="{{ $tdBase }}"><x-filament::badge :color="$sideColor($row['side'])" size="xs">{{ $row['side'] }}</x-filament::badge></td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $row['pilots'] }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $row['kills'] }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $row['deaths'] }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ number_format($row['damage_done']) }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ number_format($row['damage_taken']) }}</td>
                            <td class="{{ $tdBase }} text-right font-mono {{ $row['isk_lost'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ Battles::formatIsk((float) $row['isk_lost']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- =====================================================
         KILL FEED — table ordered by time
         ===================================================== --}}
    <x-filament::section icon="heroicon-o-fire" collapsible>
        <x-slot name="heading">Kill feed</x-slot>
        <x-slot name="description">{{ count($kill_feed) }} killmails, ordered by time</x-slot>

        <div class="{{ $tblWrap }}">
            <table class="{{ $tblBase }}">
                <thead>
                    <tr>
                        <th class="{{ $thBase }} w-20">Time</th>
                        <th class="{{ $thBase }}">Victim</th>
                        <th class="{{ $thBase }}">Ship</th>
                        <th class="{{ $thBase }}">Final blow</th>
                        <th class="{{ $thBase }} text-right">Inv.</th>
                        <th class="{{ $thBase }} text-right">ISK</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($kill_feed as $km)
                        @php
                            $victimSide  = $km['victim_id'] ? ($sides->sideByCharacterId[(int) $km['victim_id']] ?? 'C') : 'C';
                            $borderTone  = $sideTone($victimSide);
                            $isHighValue = $km['total_value'] >= 1_000_000_000;
                            $isMidValue  = $km['total_value'] >= 100_000_000 && ! $isHighValue;
                            $rowBg = $isHighValue
                                ? 'bg-amber-50 dark:bg-amber-950/20'
                                : ($isMidValue ? 'bg-amber-50/40 dark:bg-amber-950/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800/40');
                        @endphp
                        <tr class="{{ $rowBg }} transition-colors">
                            <td class="{{ $tdBase }} font-mono text-xs text-gray-500 whitespace-nowrap border-l-4 border-{{ $borderTone }}-400">
                                {{ \Carbon\Carbon::parse($km['killed_at'])->format('H:i:s') }}
                            </td>
                            <td class="{{ $tdBase }}">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if ($km['victim_id'])
                                        <x-filament::avatar src="{{ $charImg($km['victim_id'], 32) }}" size="sm" />
                                    @endif
                                    <div class="min-w-0">
                                        <div class="truncate font-medium">{{ $km['victim_name'] }}</div>
                                        @if ($km['victim_alliance_id'])
                                            <div class="flex items-center gap-1 text-xs text-gray-400">
                                                <x-filament::avatar src="{{ $allianceImg($km['victim_alliance_id'], 16) }}" :circular="false" class="w-3 h-3" />
                                                <span class="truncate">{{ $names[$km['victim_alliance_id']] ?? '#'.$km['victim_alliance_id'] }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="{{ $tdBase }}">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if ($km['ship_type_id'] > 0)
                                        <x-filament::avatar src="{{ $typeImg($km['ship_type_id'], 32) }}" :circular="false" size="sm" />
                                    @endif
                                    <span class="truncate">{{ $km['ship_name'] }}</span>
                                </div>
                            </td>
                            <td class="{{ $tdBase }}">
                                @if ($km['final_blow_char_id'])
                                    <div class="flex items-center gap-2 min-w-0">
                                        <x-filament::avatar src="{{ $charImg($km['final_blow_char_id'], 32) }}" size="sm" />
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
                            <td class="{{ $tdBase }} text-right font-mono">{{ $km['attacker_count'] }}</td>
                            <td class="{{ $tdBase }} text-right font-mono {{ $isHighValue ? 'font-bold text-amber-700 dark:text-amber-400' : '' }}">
                                {{ $km['total_value'] > 0 ? Battles::formatIsk($km['total_value']) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- =====================================================
         PILOTS — one big table, grouped via Side column
         ===================================================== --}}
    <x-filament::section icon="heroicon-o-users" collapsible>
        <x-slot name="heading">Pilots</x-slot>
        <x-slot name="description">{{ $participants->count() }} participants, sorted by ISK lost</x-slot>

        <div class="{{ $tblWrap }}">
            <table class="{{ $tblBase }}">
                <thead>
                    <tr>
                        <th class="{{ $thBase }}">Side</th>
                        <th class="{{ $thBase }}">Pilot</th>
                        <th class="{{ $thBase }}">Ship</th>
                        <th class="{{ $thBase }}">Alliance</th>
                        <th class="{{ $thBase }} text-right">Kills</th>
                        <th class="{{ $thBase }} text-right">FB</th>
                        <th class="{{ $thBase }} text-right">Dmg done</th>
                        <th class="{{ $thBase }} text-right">Dmg taken</th>
                        <th class="{{ $thBase }} text-right">Deaths</th>
                        <th class="{{ $thBase }} text-right">ISK lost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($participants as $p)
                        @php
                            $cid          = (int) $p->character_id;
                            $side         = $sides->sideByCharacterId[$cid] ?? 'C';
                            $charName     = $names[$cid] ?? 'Character #'.$cid;
                            $allianceName = $p->alliance_id ? ($names[(int) $p->alliance_id] ?? '#'.$p->alliance_id) : '—';
                            $ship         = $primaryShipOf($cid);
                        @endphp
                        <tr class="{{ $trHover }}">
                            <td class="{{ $tdBase }}"><x-filament::badge :color="$sideColor($side)" size="xs">{{ $side }}</x-filament::badge></td>
                            <td class="{{ $tdBase }}">
                                <div class="flex items-center gap-2 min-w-0">
                                    <x-filament::avatar src="{{ $charImg($cid, 32) }}" size="sm" />
                                    <span class="truncate font-medium">{{ $charName }}</span>
                                </div>
                            </td>
                            <td class="{{ $tdBase }}">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if ($ship['type_id'])
                                        <x-filament::avatar src="{{ $typeImg($ship['type_id'], 32) }}" :circular="false" size="sm" />
                                    @endif
                                    <span class="truncate">{{ $ship['name'] }}</span>
                                </div>
                            </td>
                            <td class="{{ $tdBase }}">
                                <div class="flex items-center gap-2 min-w-0 text-gray-500">
                                    @if ($p->alliance_id)
                                        <x-filament::avatar src="{{ $allianceImg((int) $p->alliance_id, 20) }}" :circular="false" class="w-4 h-4" />
                                    @endif
                                    <span class="truncate">{{ $allianceName }}</span>
                                </div>
                            </td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $p->kills }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $p->final_blows }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ number_format($p->damage_done) }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ number_format($p->damage_taken) }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $p->deaths }}</td>
                            <td class="{{ $tdBase }} text-right font-mono {{ $p->isk_lost > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                {{ Battles::formatIsk((float) $p->isk_lost) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- =====================================================
         SYSTEMS — table
         ===================================================== --}}
    <x-filament::section icon="heroicon-o-map">
        <x-slot name="heading">Systems</x-slot>
        <x-slot name="description">Where the fighting happened</x-slot>

        <div class="{{ $tblWrap }}">
            <table class="{{ $tblBase }}">
                <thead>
                    <tr>
                        <th class="{{ $thBase }}">Sec</th>
                        <th class="{{ $thBase }}">System</th>
                        <th class="{{ $thBase }} text-right">Kills</th>
                        <th class="{{ $thBase }} text-right">ISK lost</th>
                        <th class="{{ $thBase }}">First kill</th>
                        <th class="{{ $thBase }}">Last kill</th>
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
                        <tr class="{{ $trHover }}">
                            <td class="{{ $tdBase }} font-mono {{ $sysSecSCls }} font-semibold">{{ number_format($sysSecS, 1) }}</td>
                            <td class="{{ $tdBase }} font-medium">{{ $s->solarSystem?->name ?? '#'.$s->solar_system_id }}</td>
                            <td class="{{ $tdBase }} text-right font-mono">{{ $s->kill_count }}</td>
                            <td class="{{ $tdBase }} text-right font-mono {{ $s->isk_lost > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ Battles::formatIsk((float) $s->isk_lost) }}</td>
                            <td class="{{ $tdBase }} text-gray-500 font-mono text-xs">{{ $s->first_kill_at?->format('Y-m-d H:i') }}</td>
                            <td class="{{ $tdBase }} text-gray-500 font-mono text-xs">{{ $s->last_kill_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

</x-filament-panels::page>
