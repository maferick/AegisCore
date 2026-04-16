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
    $charImg = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/characters/{$id}/portrait?size={$size}" : null;
    $corpImg = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/corporations/{$id}/logo?size={$size}" : null;
    $allianceImg = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/alliances/{$id}/logo?size={$size}" : null;
    $typeImg = fn (?int $id, int $size = 32): ?string => $id ? "https://images.evetech.net/types/{$id}/icon?size={$size}" : null;
    $typeRender = fn (?int $id, int $size = 64): ?string => $id ? "https://images.evetech.net/types/{$id}/render?size={$size}" : null;

    // Primary ship per pilot: the hull they appeared in most within
    // the theater. Ties broken by higher type_id (arbitrary but stable).
    $primaryShipOf = function (int $characterId) use ($ships_by_character, $ship_names): array {
        $rows = $ships_by_character[$characterId] ?? [];
        if ($rows === []) return ['type_id' => null, 'name' => '—'];
        arsort($rows);
        $tid = (int) array_key_first($rows);
        return ['type_id' => $tid, 'name' => $ship_names[$tid] ?? ('#' . $tid)];
    };
@endphp

<x-filament-panels::page>
    {{-- Header --------------------------------------------------- --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow p-6 mb-6">
        <div class="flex justify-between items-start flex-wrap gap-4">
            <div>
                <h2 class="text-xl font-semibold">{{ $theater->primarySystem?->name ?? '#'.$theater->primary_system_id }}</h2>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $theater->region?->name ?? '—' }}
                    · {{ $theater->start_time?->format('Y-m-d H:i') }} → {{ $theater->end_time?->format('H:i') }}
                    @php $dur = $theater->durationSeconds(); @endphp
                    · {{ sprintf('%02d:%02d:%02d', intdiv($dur, 3600), intdiv($dur % 3600, 60), $dur % 60) }} duration
                    @if ($theater->locked_at)
                        · <span class="text-xs px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-800">Locked</span>
                    @else
                        · <span class="text-xs px-2 py-0.5 rounded bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-200">Live</span>
                    @endif
                </div>
            </div>
            <div class="text-right">
                <div class="text-sm text-gray-500 dark:text-gray-400">Total loss</div>
                <div class="text-2xl font-mono font-bold">{{ Battles::formatIsk((float) $theater->total_isk_lost) }} ISK</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $theater->total_kills }} kills · {{ $theater->participant_count }} pilots · {{ $theater->system_count }} systems
                </div>
            </div>
        </div>
        @if ($viewer === null || $viewer->bloc_unresolved)
            <div class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                Side labels are inferred from the two largest blocs in this fight (you haven't confirmed a coalition on
                <a href="/portal/account-settings" class="underline">account settings</a>).
            </div>
        @endif
    </div>

    {{-- Side summary ---------------------------------------------- --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        @foreach ([
            S::SIDE_A => [$sideALabel, 'blue', $sides->sideABlocId],
            S::SIDE_B => [$sideBLabel, 'red', $sides->sideBBlocId],
            S::SIDE_C => ['Other / third parties', 'gray', null],
        ] as $sideKey => $meta)
            @php
                [$label, $tone, $blocId] = $meta;
                $t = $side_totals[$sideKey];
                $toneBg = [
                    'blue' => 'bg-blue-50 dark:bg-blue-950',
                    'red' => 'bg-red-50 dark:bg-red-950',
                    'gray' => 'bg-gray-50 dark:bg-gray-900',
                ][$tone] ?? 'bg-gray-50 dark:bg-gray-900';
            @endphp
            <div class="fi-section {{ $toneBg }} rounded-xl shadow p-6">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Side {{ $sideKey }}</div>
                <div class="text-lg font-semibold">{{ $label }}</div>
                <div class="mt-4 grid grid-cols-2 gap-2 text-sm">
                    <div class="text-gray-500">Pilots</div><div class="text-right font-mono">{{ $t['pilots'] }}</div>
                    <div class="text-gray-500">Kills</div><div class="text-right font-mono">{{ $t['kills'] }}</div>
                    <div class="text-gray-500">Deaths</div><div class="text-right font-mono">{{ $t['deaths'] }}</div>
                    <div class="text-gray-500">ISK lost</div><div class="text-right font-mono">{{ Battles::formatIsk($t['isk_lost']) }}</div>
                    <div class="text-gray-500">ISK killed</div><div class="text-right font-mono">{{ Battles::formatIsk($t['isk_killed']) }}</div>
                    <div class="text-gray-500">Damage done</div><div class="text-right font-mono">{{ number_format($t['damage_done']) }}</div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Kill feed ------------------------------------------------- --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow p-6 mb-6">
        <h3 class="text-lg font-semibold mb-4">Kill feed ({{ count($kill_feed) }})</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2">Time</th>
                        <th class="py-2">Victim</th>
                        <th class="py-2">Ship</th>
                        <th class="py-2">Final blow</th>
                        <th class="py-2 text-right">Attackers</th>
                        <th class="py-2 text-right">ISK</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($kill_feed as $km)
                        <tr>
                            <td class="py-2 font-mono text-xs text-gray-500 whitespace-nowrap">
                                {{ \Carbon\Carbon::parse($km['killed_at'])->format('H:i:s') }}
                            </td>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    @if ($km['victim_id'])
                                        <img src="{{ $charImg($km['victim_id'], 32) }}" alt="" class="w-6 h-6 rounded" loading="lazy" />
                                    @endif
                                    <div class="min-w-0">
                                        <div class="truncate">{{ $km['victim_name'] }}</div>
                                        @if ($km['victim_alliance_id'])
                                            <div class="flex items-center gap-1 text-xs text-gray-500">
                                                <img src="{{ $allianceImg($km['victim_alliance_id'], 16) }}" alt="" class="w-3 h-3" loading="lazy" />
                                                <span class="truncate">{{ $names[$km['victim_alliance_id']] ?? '#'.$km['victim_alliance_id'] }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    @if ($km['ship_type_id'] > 0)
                                        <img src="{{ $typeImg($km['ship_type_id'], 32) }}" alt="" class="w-6 h-6" loading="lazy" />
                                    @endif
                                    <span class="truncate">{{ $km['ship_name'] }}</span>
                                </div>
                            </td>
                            <td class="py-2">
                                @if ($km['final_blow_char_id'])
                                    <div class="flex items-center gap-2">
                                        <img src="{{ $charImg($km['final_blow_char_id'], 32) }}" alt="" class="w-6 h-6 rounded" loading="lazy" />
                                        <div class="min-w-0">
                                            <div class="truncate">{{ $km['final_blow_name'] }}</div>
                                            @if ($km['final_blow_ship_id'])
                                                <div class="text-xs text-gray-500 truncate">{{ $ship_names[$km['final_blow_ship_id']] ?? '#'.$km['final_blow_ship_id'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-2 text-right font-mono">{{ $km['attacker_count'] }}</td>
                            <td class="py-2 text-right font-mono">{{ Battles::formatIsk($km['total_value']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Alliance table -------------------------------------------- --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow p-6 mb-6">
        <h3 class="text-lg font-semibold mb-4">Alliances</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2">Alliance</th>
                        <th class="py-2">Side</th>
                        <th class="py-2 text-right">Pilots</th>
                        <th class="py-2 text-right">Kills</th>
                        <th class="py-2 text-right">Deaths</th>
                        <th class="py-2 text-right">Damage done</th>
                        <th class="py-2 text-right">Damage taken</th>
                        <th class="py-2 text-right">ISK lost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($alliance_rows as $row)
                        <tr>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    @if ($row['alliance_id'] > 0)
                                        <img src="{{ $allianceImg($row['alliance_id'], 32) }}" alt="" class="w-6 h-6" loading="lazy" />
                                    @endif
                                    <div class="min-w-0">
                                        <div class="truncate">{{ $row['alliance_name'] }}</div>
                                        @if ($row['alliance_id'] > 0)
                                            <div class="text-xs font-mono text-gray-400">#{{ $row['alliance_id'] }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="py-2 font-mono text-xs">{{ $row['side'] }}</td>
                            <td class="py-2 text-right font-mono">{{ $row['pilots'] }}</td>
                            <td class="py-2 text-right font-mono">{{ $row['kills'] }}</td>
                            <td class="py-2 text-right font-mono">{{ $row['deaths'] }}</td>
                            <td class="py-2 text-right font-mono">{{ number_format($row['damage_done']) }}</td>
                            <td class="py-2 text-right font-mono">{{ number_format($row['damage_taken']) }}</td>
                            <td class="py-2 text-right font-mono">{{ Battles::formatIsk($row['isk_lost']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Pilot table ----------------------------------------------- --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow p-6 mb-6">
        <h3 class="text-lg font-semibold mb-4">Pilots</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2">Side</th>
                        <th class="py-2">Pilot</th>
                        <th class="py-2">Ship</th>
                        <th class="py-2">Alliance</th>
                        <th class="py-2 text-right">Kills</th>
                        <th class="py-2 text-right">FB</th>
                        <th class="py-2 text-right">Dmg done</th>
                        <th class="py-2 text-right">Dmg taken</th>
                        <th class="py-2 text-right">Deaths</th>
                        <th class="py-2 text-right">ISK lost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($participants as $p)
                        @php
                            $cid = (int) $p->character_id;
                            $side = $sides->sideByCharacterId[$cid] ?? 'C';
                            $charName = $names[$cid] ?? 'Character #'.$cid;
                            $allianceName = $p->alliance_id ? ($names[(int) $p->alliance_id] ?? '#'.$p->alliance_id) : '—';
                            $ship = $primaryShipOf($cid);
                        @endphp
                        <tr>
                            <td class="py-2 font-mono text-xs">{{ $side }}</td>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    <img src="{{ $charImg($cid, 32) }}" alt="" class="w-6 h-6 rounded" loading="lazy" />
                                    <span class="truncate">{{ $charName }}</span>
                                </div>
                            </td>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    @if ($ship['type_id'])
                                        <img src="{{ $typeImg($ship['type_id'], 32) }}" alt="" class="w-6 h-6" loading="lazy" />
                                    @endif
                                    <span class="truncate">{{ $ship['name'] }}</span>
                                </div>
                            </td>
                            <td class="py-2">
                                <div class="flex items-center gap-2 text-gray-500">
                                    @if ($p->alliance_id)
                                        <img src="{{ $allianceImg((int) $p->alliance_id, 20) }}" alt="" class="w-4 h-4" loading="lazy" />
                                    @endif
                                    <span class="truncate">{{ $allianceName }}</span>
                                </div>
                            </td>
                            <td class="py-2 text-right font-mono">{{ $p->kills }}</td>
                            <td class="py-2 text-right font-mono">{{ $p->final_blows }}</td>
                            <td class="py-2 text-right font-mono">{{ number_format($p->damage_done) }}</td>
                            <td class="py-2 text-right font-mono">{{ number_format($p->damage_taken) }}</td>
                            <td class="py-2 text-right font-mono">{{ $p->deaths }}</td>
                            <td class="py-2 text-right font-mono">{{ Battles::formatIsk((float) $p->isk_lost) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Systems --------------------------------------------------- --}}
    <div class="fi-section bg-white dark:bg-gray-900 rounded-xl shadow p-6">
        <h3 class="text-lg font-semibold mb-4">Systems</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2">System</th>
                        <th class="py-2 text-right">Kills</th>
                        <th class="py-2 text-right">ISK lost</th>
                        <th class="py-2">First kill</th>
                        <th class="py-2">Last kill</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($systems as $s)
                        <tr>
                            <td class="py-2">{{ $s->solarSystem?->name ?? '#'.$s->solar_system_id }}</td>
                            <td class="py-2 text-right font-mono">{{ $s->kill_count }}</td>
                            <td class="py-2 text-right font-mono">{{ Battles::formatIsk((float) $s->isk_lost) }}</td>
                            <td class="py-2 text-gray-500">{{ $s->first_kill_at?->format('Y-m-d H:i') }}</td>
                            <td class="py-2 text-gray-500">{{ $s->last_kill_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
