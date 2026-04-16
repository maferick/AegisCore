<x-filament-panels::page>
    @php
        $user = auth()->user();
        $character = $user?->characters()->first();

        $standings = collect();
        if ($character) {
            $standings = \App\Domains\UsersCharacters\Models\CharacterStanding::query()
                ->whereIn('contact_type', ['corporation', 'alliance', 'faction'])
                ->orderByDesc('standing')
                ->limit(100)
                ->get();
        }
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Corp & Alliance Standings</x-slot>
            <x-slot name="description">
                Standings from your corporation and alliance contact lists. Used for battle-report friendly/hostile tagging.
            </x-slot>

            @if($standings->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-700">
                                <th class="py-2 pr-4">Entity</th>
                                <th class="py-2 pr-4">Type</th>
                                <th class="py-2 pr-4">Standing</th>
                                <th class="py-2 pr-4">Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($standings as $s)
                                @php
                                    $standingVal = (float) $s->standing;
                                    $color = $standingVal >= 5 ? 'success' : ($standingVal <= -5 ? 'danger' : ($standingVal > 0 ? 'info' : ($standingVal < 0 ? 'warning' : 'gray')));
                                @endphp
                                <tr class="border-b border-gray-800">
                                    <td class="py-2 pr-4">
                                        <div class="flex items-center gap-2">
                                            @if($s->contact_type === 'corporation' && $s->contact_id)
                                                <img src="https://images.evetech.net/corporations/{{ $s->contact_id }}/logo?size=32"
                                                     alt="" referrerpolicy="no-referrer" class="w-5 h-5 rounded">
                                            @elseif($s->contact_type === 'alliance' && $s->contact_id)
                                                <img src="https://images.evetech.net/alliances/{{ $s->contact_id }}/logo?size=32"
                                                     alt="" referrerpolicy="no-referrer" class="w-5 h-5 rounded">
                                            @endif
                                            <span class="font-mono text-xs">{{ $s->contact_name ?? '#'.$s->contact_id }}</span>
                                        </div>
                                    </td>
                                    <td class="py-2 pr-4">
                                        <x-filament::badge color="gray" size="sm">{{ $s->contact_type }}</x-filament::badge>
                                    </td>
                                    <td class="py-2 pr-4">
                                        <x-filament::badge :color="$color" size="sm">{{ number_format($standingVal, 1) }}</x-filament::badge>
                                    </td>
                                    <td class="py-2 pr-4 text-xs text-gray-500">
                                        {{ $s->owner_type }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($standings->count() >= 100)
                    <p class="text-xs text-gray-500 mt-2">Showing first 100 standings.</p>
                @endif
            @else
                <p class="text-sm text-gray-400">No standings synced yet.</p>
                <p class="text-xs text-gray-500 mt-1">
                    Sync standings via
                    <a href="{{ route('account.settings') }}#standings" class="text-primary-400 underline">Account Settings</a>.
                </p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
