<x-filament-panels::page>
    @php
        $user = auth()->user();
        $character = $user?->characters()->first();
        $marketToken = $character
            ? \App\Domains\UsersCharacters\Models\EveMarketToken::where('character_id', $character->character_id)->first()
            : null;
        $ssoConfigured = \App\Services\Eve\Sso\EveSsoClient::isConfigured();

        $watchedStructures = $user
            ? \App\Domains\Markets\Models\MarketWatchedLocation::where('owner_user_id', $user->id)->get()
            : collect();
    @endphp

    <div class="space-y-6">
        {{-- Market token status --}}
        <x-filament::section>
            <x-slot name="heading">Market Data Authorization</x-slot>
            <x-slot name="description">
                Authorize ESI market access to enable structure polling and pricing data.
            </x-slot>

            @if($marketToken)
                <div class="flex items-center gap-3">
                    <x-filament::badge color="success">Authorized</x-filament::badge>
                    <span class="text-sm text-gray-400">
                        as {{ $marketToken->character_name }} &mdash;
                        expires {{ $marketToken->expires_at->diffForHumans() }}
                    </span>
                </div>
            @elseif($ssoConfigured)
                <p class="text-sm text-gray-400">
                    No market token. Authorize via
                    <a href="{{ route('account.settings') }}#market" class="text-primary-400 underline">Account Settings</a>
                    to enable structure polling.
                </p>
            @else
                <p class="text-sm text-gray-500">EVE SSO is not configured on this deployment.</p>
            @endif
        </x-filament::section>

        {{-- Watched structures --}}
        <x-filament::section>
            <x-slot name="heading">Watched Structures</x-slot>
            <x-slot name="description">
                Structures your character is polling for market data.
            </x-slot>

            @if($watchedStructures->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-700">
                                <th class="py-2 pr-4">Name</th>
                                <th class="py-2 pr-4">Type</th>
                                <th class="py-2 pr-4">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($watchedStructures as $s)
                                <tr class="border-b border-gray-800">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $s->name ?? 'ID '.$s->location_id }}</td>
                                    <td class="py-2 pr-4">
                                        <x-filament::badge :color="$s->location_type === 'npc_station' ? 'gray' : 'primary'" size="sm">
                                            {{ $s->location_type }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="py-2 pr-4">
                                        <x-filament::badge :color="$s->enabled ? 'success' : 'danger'" size="sm">
                                            {{ $s->enabled ? 'Active' : 'Disabled' }}
                                        </x-filament::badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-gray-400">No structures watched yet.</p>
            @endif

            <p class="text-xs text-gray-500 mt-3">
                To add or remove structures, use
                <a href="{{ route('account.settings') }}#structures" class="text-primary-400 underline">Account Settings</a>.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
