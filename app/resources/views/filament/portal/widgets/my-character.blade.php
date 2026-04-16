<x-filament-widgets::widget>
    @if($character)
    <x-filament::section>
        <div style="display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap;">
            {{-- Character portrait --}}
            <img src="https://images.evetech.net/characters/{{ $character->character_id }}/portrait?size=128"
                 alt="{{ $character->name }}"
                 referrerpolicy="no-referrer"
                 style="width: 80px; height: 80px; border-radius: 8px; border: 2px solid rgba(79, 208, 208, 0.3);">

            {{-- Identity block --}}
            <div style="flex: 1; min-width: 200px;">
                <div style="font-size: 1.25rem; font-weight: 700; color: #e5e5e7;">
                    {{ $character->name }}
                </div>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.35rem; flex-wrap: wrap;">
                    @if($character->corporation_id)
                        <img src="https://images.evetech.net/corporations/{{ $character->corporation_id }}/logo?size=64"
                             alt="{{ $corpName ?? 'Corp' }}"
                             referrerpolicy="no-referrer"
                             style="width: 20px; height: 20px; border-radius: 3px;">
                        <span style="font-size: 0.85rem; color: #7a7a82;">{{ $corpName ?? 'Corp #'.$character->corporation_id }}</span>
                    @endif
                    @if($character->alliance_id)
                        <span style="color: #3a3a42; margin: 0 0.15rem;">/</span>
                        <img src="https://images.evetech.net/alliances/{{ $character->alliance_id }}/logo?size=64"
                             alt="{{ $allianceName ?? 'Alliance' }}"
                             referrerpolicy="no-referrer"
                             style="width: 20px; height: 20px; border-radius: 3px;">
                        <span style="font-size: 0.85rem; color: #7a7a82;">{{ $allianceName ?? 'Alliance #'.$character->alliance_id }}</span>
                    @endif
                </div>
            </div>

            {{-- Stats --}}
            <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                <div style="text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #4ade80;">{{ number_format($kills) }}</div>
                    <div style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.1em; color: #7a7a82;">Kills</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #ff3838;">{{ number_format($deaths) }}</div>
                    <div style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.1em; color: #7a7a82;">Deaths</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #e5a900;">
                        @if($iskLost >= 1_000_000_000_000)
                            {{ number_format($iskLost / 1_000_000_000_000, 1) }}T
                        @elseif($iskLost >= 1_000_000_000)
                            {{ number_format($iskLost / 1_000_000_000, 1) }}B
                        @elseif($iskLost >= 1_000_000)
                            {{ number_format($iskLost / 1_000_000, 1) }}M
                        @else
                            {{ number_format($iskLost, 0) }}
                        @endif
                    </div>
                    <div style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.1em; color: #7a7a82;">ISK Lost</div>
                </div>
            </div>
        </div>
    </x-filament::section>
    @else
    <x-filament::section>
        <p style="color: #7a7a82;">No EVE character linked. Log in with EVE SSO to see your profile.</p>
    </x-filament::section>
    @endif
</x-filament-widgets::widget>
