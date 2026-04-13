{{-- /admin/sde-status — full history of daily SDE drift checks. --}}
<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Check history</x-slot>
            <x-slot name="description">
                One row per daily run of <code>reference:check-sde-version</code>.
                Dispatched by the scheduler container at 08:00 UTC.
                Trigger an ad-hoc check with <code>make sde-check</code>.
            </x-slot>

            @php
                $checks = $this->getChecks();
            @endphp

            @if ($checks->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No checks recorded yet. The first scheduled run lands at 08:00 UTC.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2">Checked</th>
                                <th class="px-3 py-2">Pinned</th>
                                <th class="px-3 py-2">Upstream</th>
                                <th class="px-3 py-2">Bump</th>
                                <th class="px-3 py-2">HTTP</th>
                                <th class="px-3 py-2">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($checks as $check)
                                <tr>
                                    <td class="px-3 py-2 font-mono text-xs">
                                        {{ $check->checked_at->format('Y-m-d H:i:s') }} UTC
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs">
                                        {{ $check->pinned_version ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs break-all">
                                        {{ $check->upstream_version ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        @if ($check->is_bump_available)
                                            <span class="inline-flex items-center rounded-md bg-amber-500/20 px-2 py-0.5 text-xs font-medium text-amber-300 ring-1 ring-inset ring-amber-500/40">
                                                yes
                                            </span>
                                        @else
                                            <span class="text-gray-500">no</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs">
                                        {{ $check->http_status ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-red-400">
                                        {{ $check->notes ?? '' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $checks->links() }}
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
