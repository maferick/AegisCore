<x-filament-panels::page>
    @if (! $is_donor)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            FC attestations are a donor-tier feature. Donate to unlock — the
            button is on the dashboard.
        </div>
    @else
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">
                Your private FC attestations (Mode A — only you can see this
                list). Append-only: re-marking a sub-fleet adds a new row,
                latest supersedes for calibration purposes.
            </p>

            @if ($rows->isEmpty())
                <p class="text-sm text-gray-500 italic">No attestations yet. On any battle report, a sub-fleet's "Mark FC" button records one here.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-4">When</th>
                            <th class="py-2 pr-4">Battle</th>
                            <th class="py-2 pr-4">Alliance</th>
                            <th class="py-2 pr-4">Sub-fleet</th>
                            <th class="py-2 pr-4">Attested FC</th>
                            <th class="py-2 pr-4">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-4 whitespace-nowrap">
                                    {{ \Carbon\Carbon::parse($r->attested_at)->diffForHumans() }}
                                </td>
                                <td class="py-2 pr-4">
                                    @if (! empty($r->public_slug))
                                        <a href="/portal/battles/{{ $r->battle_id }}" class="text-cyan-500 hover:underline">{{ $r->public_slug }}</a>
                                    @else
                                        #{{ $r->battle_id }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $r->alliance_name ?? ('Alliance #'.$r->alliance_id) }}</td>
                                <td class="py-2 pr-4">SF {{ $r->sub_fleet_id }} (v{{ $r->partition_algo_version }})</td>
                                <td class="py-2 pr-4">{{ $r->attested_character_name ?? ('char_'.$r->attested_character_id) }}</td>
                                <td class="py-2 pr-4 text-gray-500 italic">{{ $r->user_note }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</x-filament-panels::page>
