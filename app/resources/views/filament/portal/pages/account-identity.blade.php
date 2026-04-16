<x-filament-panels::page>
    @php
        $user = auth()->user();
        $character = $user?->characters()->first();
        $isDonor = $user && \App\Domains\UsersCharacters\Models\EveDonation::query()
            ->join('eve_donor_benefits', 'eve_donations.donor_character_id', '=', 'eve_donor_benefits.donor_character_id')
            ->where('eve_donor_benefits.ad_free_until', '>=', now())
            ->whereIn('eve_donations.donor_character_id', $user->characters->pluck('character_id'))
            ->exists();

        $viewerContext = $character
            ? \App\Domains\UsersCharacters\Models\ViewerContext::where('character_id', $character->id)->first()
            : null;

        $bloc = $viewerContext?->bloc_id
            ? \App\Domains\UsersCharacters\Models\CoalitionBloc::find($viewerContext->bloc_id)
            : null;
    @endphp

    <div class="space-y-6">
        {{-- Identity card --}}
        <x-filament::section>
            <x-slot name="heading">Identity</x-slot>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Account email</span>
                    <div class="font-mono mt-0.5">{{ $user->email }}</div>
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Donor status</span>
                    <div class="mt-0.5">
                        @if($isDonor)
                            <x-filament::badge color="success">Active</x-filament::badge>
                        @else
                            <x-filament::badge color="gray">Not a donor</x-filament::badge>
                        @endif
                    </div>
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Linked characters</span>
                    <div class="mt-0.5 space-y-1">
                        @forelse($user->characters as $c)
                            <div class="flex items-center gap-2">
                                <img src="https://images.evetech.net/characters/{{ $c->character_id }}/portrait?size=64"
                                     alt="{{ $c->name }}" referrerpolicy="no-referrer"
                                     class="w-6 h-6 rounded">
                                <span class="font-mono text-sm">{{ $c->name }}</span>
                                <span class="text-xs text-gray-500">#{{ $c->character_id }}</span>
                            </div>
                        @empty
                            <x-filament::badge color="gray">None</x-filament::badge>
                        @endforelse
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Coalition affiliation --}}
        <x-filament::section>
            <x-slot name="heading">Coalition Affiliation</x-slot>
            <x-slot name="description">
                Which coalition your character views the universe from. Drives friendly / hostile / neutral tagging.
            </x-slot>

            @if($viewerContext)
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-400">Current bloc:</span>
                    @if($bloc)
                        <x-filament::badge :color="$viewerContext->bloc_unresolved ? 'warning' : 'success'">
                            {{ $bloc->display_name }}
                            @if($viewerContext->bloc_unresolved) (unconfirmed) @endif
                        </x-filament::badge>
                    @else
                        <x-filament::badge color="gray">Not set</x-filament::badge>
                    @endif
                </div>
                <p class="text-xs text-gray-500 mt-2">
                    To change your coalition, go to
                    <a href="{{ route('account.settings') }}#coalition" class="text-primary-400 underline">Account Settings (legacy)</a>.
                </p>
            @else
                <p class="text-sm text-gray-400">No viewer context created yet. Visit the legacy account settings to set up your coalition.</p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
