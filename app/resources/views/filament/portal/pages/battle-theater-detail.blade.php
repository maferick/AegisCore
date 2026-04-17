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

    // Banner headline = biggest alliance on the side. Bloc name (e.g.
    // "WinterCo") becomes a subtitle — the alliance is what people
    // recognise on an actual battle report.
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

    // Side → Filament semantic colour (for x-filament::badge).
    $sideColor = fn (string $s): string => match ($s) {
        'A' => 'info',
        'B' => 'danger',
        default => 'gray',
    };

    // Side → concrete Tailwind palette (for bare utility classes —
    // Tailwind JIT only ships the colours it sees as literals).
    $sideTone = fn (string $s): string => match ($s) {
        'A' => 'blue',
        'B' => 'red',
        default => 'gray',
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
@endphp

<x-filament-panels::page>

    {{-- =====================================================
         HEADER — system + top-line counts
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
         VS BANNER — big alliance logos, efficiency split
         (custom markup; Filament has no banner primitive)
         ===================================================== --}}
    <x-filament::section>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
            {{-- Side A --}}
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

            {{-- VS --}}
            <div class="text-center">
                <div class="text-sm font-black tracking-[0.4em] text-gray-300 dark:text-gray-600">VS</div>
                <div class="mt-2 text-xs text-gray-400 dark:text-gray-500">ISK efficiency</div>
                <div class="mt-1 flex items-baseline justify-center gap-3">
                    <span class="text-2xl font-mono font-bold text-blue-600 dark:text-blue-400">{{ $effA }}%</span>
                    <span class="text-gray-300 dark:text-gray-600">—</span>
                    <span class="text-2xl font-mono font-bold text-red-600 dark:text-red-400">{{ $effB }}%</span>
                </div>
            </div>

            {{-- Side B --}}
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
         SIDE STAT CARDS
         ===================================================== --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach ([
            S::SIDE_A => [$sideAHeadline, 'info', $blocA],
            S::SIDE_B => [$sideBHeadline, 'danger', $blocB],
            S::SIDE_C => ['Other / third parties', 'gray', null],
        ] as $sideKey => $meta)
            @php
                [$label, $color, $sub] = $meta;
                $tone = $color === 'info' ? 'blue' : ($color === 'danger' ? 'red' : 'gray');
                $t    = $side_totals[$sideKey];
                $traded = (float) ($t['isk_killed'] + $t['isk_lost']);
                $eff  = $traded > 0 ? round($t['isk_killed'] / $traded * 100, 1) : null;
            @endphp
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-filament::badge :color="$color" size="xs">Side {{ $sideKey }}</x-filament::badge>
                        <span class="text-{{ $tone }}-600 dark:text-{{ $tone }}-400">{{ $label }}</span>
                    </div>
                </x-slot>
                @if ($sub && $sub !== $label)
                    <x-slot name="description">{{ $sub }} bloc</x-slot>
                @endif
                @if ($eff !== null)
                    <x-slot name="headerEnd">
                        <x-filament::badge :color="$color">{{ $eff }}% eff</x-filament::badge>
                    </x-slot>
                @endif

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
            </x-filament::section>
        @endforeach
    </div>

    {{-- =====================================================
         MOST VALUABLE KILLS per side
         ===================================================== --}}
    @if (!empty($most_valuable_kills[S::SIDE_A]) || !empty($most_valuable_kills[S::SIDE_B]))
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ([
            S::SIDE_A => ['Top kills — Side A', 'info', $sideAHeadline],
            S::SIDE_B => ['Top kills — Side B', 'danger', $sideBHeadline],
        ] as $sideKey => $meta)
            @php
                [$title, $color, $labelFor] = $meta;
                $tone = $color === 'info' ? 'blue' : ($color === 'danger' ? 'red' : 'gray');
                $rows = $most_valuable_kills[$sideKey] ?? [];
            @endphp
            <x-filament::section icon="heroicon-o-trophy" :icon-color="$color">
                <x-slot name="heading">{{ $title }}</x-slot>
                <x-slot name="description">Kills by {{ $labelFor }}</x-slot>
                @if ($rows === [])
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">No kills credited to this side.</p>
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
                                    <div class="text-xs text-gray-400 dark:text-gray-500 font-mono">{{ $km['attacker_count'] }} inv.</div>
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
         SHIP COMPOSITION per side
         ===================================================== --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ([
            S::SIDE_A => ['Composition — Side A', 'info', $sideAHeadline],
            S::SIDE_B => ['Composition — Side B', 'danger', $sideBHeadline],
        ] as $sideKey => $meta)
            @php
                [$title, $color, $labelFor] = $meta;
                $tone = $color === 'info' ? 'blue' : ($color === 'danger' ? 'red' : 'gray');
                $rows = $composition[$sideKey] ?? [];
                $max = 0;
                foreach ($rows as $r) { if ($r['count'] > $max) $max = $r['count']; }
            @endphp
            <x-filament::section icon="heroicon-o-squares-2x2" :icon-color="$color">
                <x-slot name="heading">{{ $title }}</x-slot>
                <x-slot name="description">{{ $labelFor }}</x-slot>
                @if ($rows === [])
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">No ships flown for this side.</p>
                @else
                    <ul class="space-y-1.5">
                        @foreach ($rows as $r)
                            @php $pct = $max > 0 ? round($r['count'] / $max * 100) : 0; @endphp
                            <li class="flex items-center gap-2 text-sm">
                                <x-filament::avatar src="{{ $typeImg($r['sample_type_id'], 32) }}" :circular="false" size="sm" />
                                <span class="truncate flex-shrink min-w-0">{{ $r['class'] }}</span>
                                <div class="flex-1 h-1.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                                    <div class="h-1.5 rounded-full bg-{{ $tone }}-500" style="width: {{ $pct }}%"></div>
                                </div>
                                <span class="font-mono font-semibold tabular-nums w-8 text-right">{{ $r['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    {{-- =====================================================
         TOP DAMAGE DEALERS per side
         ===================================================== --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ([
            S::SIDE_A => ['Top damage — Side A', 'info'],
            S::SIDE_B => ['Top damage — Side B', 'danger'],
        ] as $sideKey => $meta)
            @php
                [$title, $color] = $meta;
                $tone = $color === 'info' ? 'blue' : ($color === 'danger' ? 'red' : 'gray');
                $rows = $top_damage[$sideKey] ?? [];
                $max = 0;
                foreach ($rows as $r) { if ($r['damage_done'] > $max) $max = $r['damage_done']; }
            @endphp
            <x-filament::section icon="heroicon-o-bolt" :icon-color="$color">
                <x-slot name="heading">{{ $title }}</x-slot>
                @if ($rows === [])
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">No damage recorded.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($rows as $r)
                            @php $pct = $max > 0 ? round($r['damage_done'] / $max * 100) : 0; @endphp
                            <li class="flex items-center gap-2 text-sm">
                                <x-filament::avatar src="{{ $charImg($r['character_id'], 32) }}" size="sm" />
                                @if ($r['ship_type_id'])
                                    <x-filament::avatar src="{{ $typeImg($r['ship_type_id'], 32) }}" :circular="false" size="sm" />
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
                                        <div class="h-1.5 rounded-full bg-{{ $tone }}-500" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="font-mono font-semibold tabular-nums text-xs w-14 text-right">{{ number_format($r['damage_done']) }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    {{-- =====================================================
         ROSTER BY SIDE
         ===================================================== --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach ([
            S::SIDE_A => ['Roster — Side A', 'info'],
            S::SIDE_B => ['Roster — Side B', 'danger'],
            S::SIDE_C => ['Third parties', 'gray'],
        ] as $sideKey => $meta)
            @php
                [$title, $color] = $meta;
                $rows = $roster_by_side[$sideKey] ?? collect();
            @endphp
            <x-filament::section icon="heroicon-o-user-group" :icon-color="$color">
                <x-slot name="heading">{{ $title }}</x-slot>
                <x-slot name="description">{{ $rows->count() }} alliance(s)</x-slot>
                @if ($rows->isEmpty())
                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">No alliances on this side.</p>
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach ($rows as $row)
                            <li class="flex items-center gap-2 pb-2 border-b border-gray-50 dark:border-gray-800/60 last:border-0">
                                @if ($row['alliance_id'] > 0)
                                    <x-filament::avatar src="{{ $allianceImg($row['alliance_id'], 32) }}" :circular="false" size="sm" />
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
            </x-filament::section>
        @endforeach
    </div>

    {{-- =====================================================
         KILL FEED — narrative stack
         ===================================================== --}}
    <x-filament::section icon="heroicon-o-fire" collapsible>
        <x-slot name="heading">Kill feed</x-slot>
        <x-slot name="description">{{ count($kill_feed) }} killmails, ordered by time</x-slot>

        <ol class="space-y-2">
            @foreach ($kill_feed as $km)
                @php
                    $victimSide  = $km['victim_id'] ? ($sides->sideByCharacterId[(int) $km['victim_id']] ?? 'C') : 'C';
                    $sideColorVictim = $sideColor($victimSide);
                    $sideToneVictim  = $sideTone($victimSide);
                    $isHighValue = $km['total_value'] >= 1_000_000_000;
                    $isMidValue  = $km['total_value'] >= 100_000_000 && ! $isHighValue;
                    $rowBg = $isHighValue
                        ? 'bg-amber-50 dark:bg-amber-950/20'
                        : ($isMidValue ? 'bg-amber-50/40 dark:bg-amber-950/10' : 'bg-gray-50 dark:bg-gray-800/40 hover:bg-gray-100 dark:hover:bg-gray-800');
                @endphp
                <li class="flex items-center gap-3 p-3 rounded-lg {{ $rowBg }} border-l-4 border-{{ $sideToneVictim }}-400 transition-colors">
                    <div class="font-mono text-xs text-gray-500 whitespace-nowrap w-16 flex-shrink-0">{{ \Carbon\Carbon::parse($km['killed_at'])->format('H:i:s') }}</div>

                    @if ($km['victim_id'])
                        <x-filament::avatar src="{{ $charImg($km['victim_id'], 32) }}" size="sm" />
                    @endif
                    @if ($km['ship_type_id'] > 0)
                        <x-filament::avatar src="{{ $typeImg($km['ship_type_id'], 32) }}" :circular="false" size="sm" />
                    @endif

                    <div class="min-w-0 flex-1 text-sm leading-snug">
                        <span class="font-medium">{{ $km['victim_name'] }}</span>
                        @if ($km['victim_alliance_id'])
                            <span class="text-gray-500 dark:text-gray-400">({{ $names[$km['victim_alliance_id']] ?? '#'.$km['victim_alliance_id'] }})</span>
                        @endif
                        <span class="text-gray-400">lost a</span>
                        <span class="font-medium">{{ $km['ship_name'] }}</span>
                        @if ($km['final_blow_name'])
                            <span class="text-gray-400">to</span>
                            <span class="font-medium">{{ $km['final_blow_name'] }}</span>
                            @if ($km['final_blow_alliance_id'])
                                <span class="text-gray-500 dark:text-gray-400">({{ $names[$km['final_blow_alliance_id']] ?? '#'.$km['final_blow_alliance_id'] }})</span>
                            @endif
                            @if ($km['final_blow_ship_id'])
                                <span class="text-gray-400">flying a {{ $ship_names[$km['final_blow_ship_id']] ?? '#'.$km['final_blow_ship_id'] }}</span>
                            @endif
                        @endif
                        <span class="text-gray-400 font-mono text-xs ml-1">· {{ $km['attacker_count'] }} involved</span>
                    </div>

                    @if ($km['final_blow_char_id'])
                        <x-filament::avatar src="{{ $charImg($km['final_blow_char_id'], 32) }}" size="sm" />
                    @endif

                    <div class="font-mono text-sm text-right flex-shrink-0 w-20 {{ $isHighValue ? 'font-bold text-amber-700 dark:text-amber-400' : ($km['total_value'] > 0 ? '' : 'text-gray-400') }}">
                        {{ $km['total_value'] > 0 ? Battles::formatIsk($km['total_value']) : '—' }}
                    </div>
                </li>
            @endforeach
        </ol>
    </x-filament::section>

    {{-- =====================================================
         PILOTS — three parallel lists, no table
         ===================================================== --}}
    <x-filament::section icon="heroicon-o-users" collapsible>
        <x-slot name="heading">Pilots</x-slot>
        <x-slot name="description">{{ $participants->count() }} participants</x-slot>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach ([
                S::SIDE_A => ['Side A', 'info', $sideAHeadline],
                S::SIDE_B => ['Side B', 'danger', $sideBHeadline],
                S::SIDE_C => ['Other', 'gray', null],
            ] as $sideKey => $meta)
                @php
                    [$title, $color, $sub] = $meta;
                    $tone = $color === 'info' ? 'blue' : ($color === 'danger' ? 'red' : 'gray');
                    $sidePilots = $participants->filter(fn ($p) => ($sides->sideByCharacterId[(int) $p->character_id] ?? 'C') === $sideKey)->values();
                @endphp
                <div>
                    <div class="mb-3 flex items-center gap-2">
                        <x-filament::badge :color="$color" size="xs">{{ $title }}</x-filament::badge>
                        <span class="text-sm font-bold text-{{ $tone }}-600 dark:text-{{ $tone }}-400 truncate">{{ $sub ?: $title }}</span>
                        <span class="text-xs text-gray-400 font-mono ml-auto flex-shrink-0">{{ $sidePilots->count() }}</span>
                    </div>
                    @if ($sidePilots->isEmpty())
                        <p class="text-sm text-gray-400 dark:text-gray-500 italic">No pilots.</p>
                    @else
                        <ul class="space-y-1.5">
                            @foreach ($sidePilots as $p)
                                @php
                                    $cid  = (int) $p->character_id;
                                    $name = $names[$cid] ?? 'Character #'.$cid;
                                    $ship = $primaryShipOf($cid);
                                    $alliance = $p->alliance_id ? ($names[(int) $p->alliance_id] ?? '#'.$p->alliance_id) : null;
                                @endphp
                                <li class="flex items-center gap-2 text-sm py-1">
                                    <x-filament::avatar src="{{ $charImg($cid, 32) }}" size="sm" class="ring-1 ring-{{ $tone }}-400/40" />
                                    @if ($ship['type_id'])
                                        <x-filament::avatar src="{{ $typeImg($ship['type_id'], 32) }}" :circular="false" size="sm" />
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-medium leading-tight">{{ $name }}</div>
                                        <div class="text-xs text-gray-400 truncate leading-tight">
                                            {{ $ship['name'] }}@if ($alliance) · {{ $alliance }}@endif
                                        </div>
                                    </div>
                                    <div class="text-right text-xs font-mono flex-shrink-0 leading-tight">
                                        @if ($p->kills > 0 || $p->final_blows > 0)
                                            <div class="text-green-600 dark:text-green-400">
                                                {{ $p->kills }}k
                                                @if ($p->final_blows > 0)
                                                    / {{ $p->final_blows }}fb
                                                @endif
                                            </div>
                                        @endif
                                        @if ($p->deaths > 0)
                                            <div class="text-red-600 dark:text-red-400">{{ Battles::formatIsk((float) $p->isk_lost) }}</div>
                                        @endif
                                        @if ($p->damage_done > 0)
                                            <div class="text-gray-400">{{ number_format($p->damage_done) }} dmg</div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- =====================================================
         SYSTEMS
         ===================================================== --}}
    <x-filament::section icon="heroicon-o-map">
        <x-slot name="heading">Systems</x-slot>
        <x-slot name="description">Where the fighting happened</x-slot>

        <ul class="space-y-2">
            @foreach ($systems as $s)
                @php
                    $sysSecS = (float) ($s->solarSystem?->security_status ?? 0.0);
                    $sysSecSCls = match (true) {
                        $sysSecS >= 0.5  => 'text-green-600 dark:text-green-400',
                        $sysSecS >= 0.0  => 'text-amber-600 dark:text-amber-400',
                        default          => 'text-red-600 dark:text-red-400',
                    };
                @endphp
                <li class="flex items-center gap-3 text-sm">
                    <span class="{{ $sysSecSCls }} font-mono font-semibold w-10 flex-shrink-0">{{ number_format($sysSecS, 1) }}</span>
                    <span class="font-medium flex-shrink-0">{{ $s->solarSystem?->name ?? '#'.$s->solar_system_id }}</span>
                    <span class="text-gray-400 font-mono text-xs">{{ $s->kill_count }} kills</span>
                    <span class="flex-1"></span>
                    <span class="font-mono {{ $s->isk_lost > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400' }}">{{ Battles::formatIsk((float) $s->isk_lost) }}</span>
                    <span class="text-gray-400 font-mono text-xs hidden md:inline">{{ $s->first_kill_at?->format('H:i') }} → {{ $s->last_kill_at?->format('H:i') }}</span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>

</x-filament-panels::page>
