{{-- SDE version-drift status card — rendered on the /admin dashboard. --}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">EVE Static Data</x-slot>

        <x-slot name="description">
            Daily check against CCP's pinned SDE tarball.
            <a href="{{ url('/admin/sde-status') }}" class="underline">Full history</a>
        </x-slot>

        @php
            // Map widget state → a muted Tailwind palette that fits the
            // EVE HUD look (cyan = friendly, amber = status, red = alert).
            $badgeClass = match ($state) {
                'ok' => 'bg-cyan-500/20 text-cyan-300 ring-cyan-500/40',
                'bump' => 'bg-amber-500/20 text-amber-300 ring-amber-500/40',
                'stalled' => 'bg-red-500/20 text-red-300 ring-red-500/40',
                default => 'bg-gray-500/20 text-gray-300 ring-gray-500/40',
            };
        @endphp

        <div class="space-y-3">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $badgeClass }}">
                    {{ $label }}
                </span>

                @if ($checked_at !== null)
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Checked {{ $checked_at->diffForHumans() }}
                    </span>
                @endif
            </div>

            @if ($description !== null)
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ $description }}</p>
            @endif

            @if ($pinned !== null || $upstream !== null)
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Pinned</dt>
                        <dd class="font-mono text-gray-900 dark:text-gray-100">
                            {{ $pinned ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Upstream</dt>
                        <dd class="font-mono text-gray-900 dark:text-gray-100 break-all">
                            {{ $upstream ?? '—' }}
                        </dd>
                    </div>
                </dl>
            @endif

            @if ($notes !== null)
                <p class="text-xs text-red-500 dark:text-red-400">{{ $notes }}</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
