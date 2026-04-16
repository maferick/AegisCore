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
    /** @var array $kill_feed */

    $sideALabel = $sides->sideABlocId ? ($blocs[$sides->sideABlocId]->display_name ?? 'Side A') : 'Side A';
    $sideBLabel = $sides->sideBBlocId ? ($blocs[$sides->sideBBlocId]->display_name ?? 'Side B') : 'No opposing bloc';

    // EVE image server helpers. CCP CDN, no auth, public cache.
    $charImg    = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/characters/{$id}/portrait?size={$size}" : null;
    $allianceImg = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/alliances/{$id}/logo?size={$size}" : null;
    $typeImg    = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/types/{$id}/icon?size={$size}" : null;

    // Primary ship per pilot: the hull they appeared in most within
    // the theater. Ties broken by higher type_id (arbitrary but stable).
    $primaryShipOf = function (int $characterId) use ($ships_by_character, $ship_names): array {
        $rows = $ships_by_character[$characterId] ?? [];
        if ($rows === []) return ['type_id' => null, 'name' => '—'];
        arsort($rows);
        $tid = (int) array_key_first($rows);
        return ['type_id' => $tid, 'name' => $ship_names[$tid] ?? ('#' . $tid)];
    };

    // Side badge Tailwind classes.
    $sideBadgeCss = fn (string $s): string => match ($s) {
        'A'     => 'bg-blue-100 text-blue-700 dark:bg-blue-900/60 dark:text-blue-200',
        'B'     => 'bg-red-100 text-red-700 dark:bg-red-900/60 dark:text-red-200',
        default => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    };

    // ISK efficiency for a side (0–100), null when no ISK was exchanged.
    $efficiency = function (array $t): ?float {
        $total = $t['isk_killed'] + $t['isk_lost'];
        return $total > 0 ? round($t['isk_killed'] / $total * 100, 1) : null;
    };
@endphp

<x-filament-panels::page>

    {{-- ============================================================
         HEADER
         ============================================================ --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">

        {{-- Region + time meta --}}
        <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-5">
            <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $theater->region?->name ?? '—' }}</span>
            <span>·</span>
            <span>{{ $theater->start_time?->format('Y-m-d H:i') }} → {{ $theater->end_time?->format('H:i') }}</span>
            <span>·</span>
            @php $dur = $theater->durationSeconds(); @endphp
            <span>{{ sprintf('%02d:%02d:%02d', intdiv($dur, 3600), intdiv($dur % 3600, 60), $dur % 60) }}</span>
            @if ($theater->locked_at)
                <span class="px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-medium">Locked</span>
            @else
                <span class="px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 font-medium">Live</span>
            @endif
        </div>

        {{-- VS banner --}}
        <div class="flex items-center gap-4 mb-6">
            <div class="flex-1 min-w-0">
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400 truncate">{{ $sideALabel }}</div>
                <div class="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider mt-0.5">Side A</div>
            </div>
            <div class="flex-shrink-0 px-4 text-center">
                <div class="text-lg font-black tracking-widest text-gray-300 dark:text-gray-600">VS</div>
            </div>
            <div class="flex-1 min-w-0 text-right">
                <div class="text-2xl font-bold text-red-600 dark:text-red-400 truncate">{{ $sideBLabel }}</div>
                <div class="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider mt-0.5">Side B</div>
            </div>
        </div>

        {{-- Key stats --}}
        <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-t border-gray-100 dark:border-gray-800 pt-4">
            <div>
                <span class="text-2xl font-mono font-bold">{{ Battles::formatIsk((float) $theater->total_isk_lost) }}</span>
                <span class="text-sm text-gray-500 dark:text-gray-400 ml-1">ISK destroyed</span>
            </div>
            <span class="text-sm text-gray-400 dark:text-gray-500">{{ $theater->total_kills }} kills</span>
            <span class="text-sm text-gray-400 dark:text-gray-500">{{ $theater->participant_count }} pilots</span>
            <span class="text-sm text-gray-400 dark:text-gray-500">{{ $theater->system_count }} systems</span>
        </div>

        @if ($viewer === null || $viewer->bloc_unresolved)
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                Side labels are inferred from the two largest blocs in this fight.
                <a href="/portal/account-settings" class="underline hover:text-gray-600 dark:hover:text-gray-300">Set your coalition</a> for viewer-relative sides.
            </p>
        @endif
    </div>

    {{-- ============================================================
         SIDE SUMMARY CARDS
         ============================================================ --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        @foreach ([
            S::SIDE_A => [$sideALabel, 'blue'],
            S::SIDE_B => [$sideBLabel, 'red'],
            S::SIDE_C => ['Other / third parties', 'gray'],
        ] as $sideKey => $meta)
            @php
                [$label, $tone] = $meta;
                $t   = $side_totals[$sideKey];
                $eff = $efficiency($t);

                $cardBorder  = match ($tone) { 'blue' => 'border-t-4 border-blue-500', 'red' => 'border-t-4 border-red-500', default => 'border-t-4 border-gray-300 dark:border-gray-600' };
                $labelColor  = match ($tone) { 'blue' => 'text-blue-600 dark:text-blue-400', 'red' => 'text-red-600 dark:text-red-400', default => 'text-gray-600 dark:text-gray-400' };
                $barColor    = match ($tone) { 'blue' => 'bg-blue-500', 'red' => 'bg-red-500', default => 'bg-gray-400' };
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

                @if ($eff !== null)
                    <div class="mb-4 h-1.5 w-full rounded-full bg-gray-100 dark:bg-gray-800">
                        <div class="h-1.5 rounded-full {{ $barColor }}" style="width: {{ min($eff, 100) }}%"></div>
                    </div>
                @endif

                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">Pilots</dt>
                    <dd class="text-right font-mono font-medium">{{ $t['pilots'] }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">Kills</dt>
                    <dd class="text-right font-mono font-medium">{{ $t['kills'] }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">Deaths</dt>
                    <dd class="text-right font-mono font-medium">{{ $t['deaths'] }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">ISK lost</dt>
                    <dd class="text-right font-mono font-medium {{ $t['isk_lost'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                        {{ Battles::formatIsk($t['isk_lost']) }}
                    </dd>

                    <dt class="text-gray-500 dark:text-gray-400">ISK killed</dt>
                    <dd class="text-right font-mono font-medium {{ $t['isk_killed'] > 0 ? 'text-green-600 dark:text-green-400' : '' }}">
                        {{ Battles::formatIsk($t['isk_killed']) }}
                    </dd>

                    <dt class="text-gray-500 dark:text-gray-400">Dmg done</dt>
                    <dd class="text-right font-mono font-medium">{{ number_format($t['damage_done']) }}</dd>
                </dl>
            </div>
        @endforeach
    </div>

    {{-- ============================================================
         KILL FEED
         ============================================================ --}}
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
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Attackers</th>
                        <th class="py-2 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">ISK</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($kill_feed as $km)
                        @php
                            $victimSide  = $km['victim_id'] ? ($sides->sideByCharacterId[(int) $km['victim_id']] ?? 'C') : 'C';
                            $isHighValue = $km['total_value'] >= 100_000_000;
                            $sideBorderCss = match ($victimSide) {
                                'A'     => 'border-l-2 border-blue-400',
                                'B'     => 'border-l-2 border-red-400',
                                default => 'border-l-2 border-gray-200 dark:border-gray-700',
                            };
                            $rowBg = $isHighValue
                                ? 'bg-amber-50 dark:bg-amber-950/20'
                                : 'hover:bg-gray-50 dark:hover:bg-gray-800/40';
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

    {{-- ============================================================
         ALLIANCE TABLE
         ============================================================ --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
        <h3 class="text-base font-semibold mb-4">Alliances</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">Alliance</th>
                        <th class="py-2 pr-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">Side</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Pilots</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Kills</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Deaths</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Dmg done</th>
                        <th class="py-2 pr-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">Dmg taken</th>
                        <th class="py-2 text-right text-xs font-medium text-gray-400 uppercase tracking-wide">ISK lost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($alliance_rows as $row)
                        <tr class="border-b border-gray-50 dark:border-gray-800/60 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                            <td class="py-2.5 pr-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if ($row['alliance_id'] > 0)
                                        <img src="{{ $allianceImg($row['alliance_id'], 32) }}" alt="" class="w-7 h-7 flex-shrink-0" loading="lazy" />
                                    @else
                                        <div class="w-7 h-7 flex-shrink-0 rounded bg-gray-100 dark:bg-gray-800"></div>
                                    @endif
                                    <span class="truncate font-medium">{{ $row['alliance_name'] }}</span>
                                </div>
                            </td>
                            <td class="py-2.5 pr-3">
                                <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold {{ $sideBadgeCss($row['side']) }}">
                                    {{ $row['side'] }}
                                </span>
                            </td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ $row['pilots'] }}</td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ $row['kills'] }}</td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ $row['deaths'] }}</td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ number_format($row['damage_done']) }}</td>
                            <td class="py-2.5 pr-3 text-right font-mono">{{ number_format($row['damage_taken']) }}</td>
                            <td class="py-2.5 text-right font-mono {{ $row['isk_lost'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                {{ Battles::formatIsk($row['isk_lost']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ============================================================
         PILOT TABLE
         ============================================================ --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
        <h3 class="text-base font-semibold mb-4">Pilots</h3>
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

    {{-- ============================================================
         SYSTEMS
         ============================================================ --}}
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
                        <tr class="border-b border-gray-50 dark:border-gray-800/60 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                            <td class="py-2.5 pr-3 font-medium">{{ $s->solarSystem?->name ?? '#'.$s->solar_system_id }}</td>
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
