<x-filament-panels::page>
    @php
        $roleOrder = ['fc', 'command', 'logi', 'mainline_dps', 'tackle', 'bomber'];
        $roleLabel = fn ($r) => match ($r) {
            'fc' => 'FC', 'logi' => 'Logistics', 'mainline_dps' => 'Mainline DPS',
            'tackle' => 'Tackle', 'bomber' => 'Bombers', 'command' => 'Command', default => ucfirst($r),
        };
        $roleColor = fn ($r) => match ($r) {
            'fc' => '#fde047', 'logi' => '#6ee7b7', 'mainline_dps' => '#93c5fd',
            'tackle' => '#67e8f9', 'bomber' => '#fdba74', 'command' => '#f0abfc', default => '#cbd5e1',
        };
        $confBand = function ($c) {
            if ($c >= 0.95) return ['sure', '#22c55e'];
            if ($c >= 0.85) return ['confident', '#84cc16'];
            return ['likely', '#eab308'];
        };
    @endphp

    @if (! $corp_id && ! $alliance_id && ! $bloc_id)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No corporation/alliance/bloc detected on your linked EVE character.
            </p>
        </div>
    @else
        @php
            $tabs = [
                'corp' => ['name' => $corp_name, 'id' => $corp_id, 'doctrines' => $corp_doctrines,
                    'logo' => $corp_id ? "https://images.evetech.net/corporations/{$corp_id}/logo?size=64" : null,
                    'label' => 'Corp'],
                'alliance' => ['name' => $alliance_name, 'id' => $alliance_id, 'doctrines' => $alliance_doctrines,
                    'logo' => $alliance_id ? "https://images.evetech.net/alliances/{$alliance_id}/logo?size=64" : null,
                    'label' => 'Alliance'],
                'bloc' => ['name' => $bloc_name, 'id' => $bloc_id, 'doctrines' => $bloc_doctrines,
                    'logo' => null, 'label' => 'Bloc'],
            ];
        @endphp
        @foreach ($tabs as $tabKey => $tab)
            @if (! $tab['id']) @continue @endif
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
                <div class="flex items-center gap-3 mb-3">
                    @if ($tab['logo'])
                        <img src="{{ $tab['logo'] }}" class="w-10 h-10 rounded" alt="">
                    @else
                        <div style="width:40px;height:40px;border-radius:4px;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:0.7em;">BLOC</div>
                    @endif
                    <div>
                        <h2 class="text-lg font-semibold">{{ $tab['label'] }}: {{ $tab['name'] }}</h2>
                        <p class="text-xs text-gray-500">{{ count($tab['doctrines']) }} active doctrine(s) adopted</p>
                    </div>
                </div>

                @if (count($tab['doctrines']) === 0)
                    <p class="text-sm text-gray-500 italic">No active doctrines yet.</p>
                @else
                    @php $byRole = collect($tab['doctrines'])->groupBy('role'); @endphp
                    @foreach ($roleOrder as $role)
                        @if (! $byRole->has($role)) @continue @endif
                        @php
                            $roleItems = $byRole->get($role);
                            $primary = $roleItems->where('bucket', 'primary')->sortByDesc('scope_n')->values();
                            $tail = $roleItems->where('bucket', 'tail')->sortByDesc('scope_n')->values();
                            $primaryCount = $primary->count();
                            $tailCount = $tail->count();
                            $collapseByDefault = $primaryCount > 30 || ($primaryCount === 0 && $tailCount > 0);
                        @endphp
                        <details @if(! $collapseByDefault) open @endif style="margin-bottom:1rem;">
                            <summary style="cursor:pointer; list-style:none; display:flex; align-items:baseline; gap:0.4rem; color: {{ $roleColor($role) }}; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.8rem; margin-bottom: 0.4rem;">
                                <span style="display:inline-block; width:0.8rem; color:#7a7a82;">{{ $collapseByDefault ? '▸' : '▾' }}</span>
                                {{ $roleLabel($role) }}
                                <span style="color:#7a7a82; font-weight:400; letter-spacing:0; text-transform:none; font-size:0.85em;">· {{ $primaryCount }}@if ($tailCount > 0)<span style="color:#4b5563;"> (+{{ $tailCount }} tail)</span>@endif</span>
                                @if ($collapseByDefault)
                                    <span style="color:#7a7a82; font-weight:400; letter-spacing:0; text-transform:none; font-size:0.75em; font-style:italic;">(click to expand)</span>
                                @endif
                            </summary>
                            @if ($primaryCount > 0)
                            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap:0.75rem;">
                                @foreach ($primary as $d)
                                    @include('filament.portal.partials.doctrine-card', ['d' => $d, 'tab' => $tab, 'confBand' => $confBand])
                                @endforeach
                            </div>
                            @endif
                            @if ($tailCount > 0)
                            <details style="margin-top:0.75rem; padding-left:0.25rem;">
                                <summary style="cursor:pointer; font-size:0.75rem; color:#7a7a82; font-style:italic;">
                                    +{{ $tailCount }} low-share variant fit{{ $tailCount > 1 ? 's' : '' }} (scope share &lt; 15% or ranked 4+)
                                </summary>
                                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap:0.75rem; margin-top:0.5rem;">
                                    @foreach ($tail as $d)
                                        @include('filament.portal.partials.doctrine-card', ['d' => $d, 'tab' => $tab, 'confBand' => $confBand])
                                    @endforeach
                                </div>
                            </details>
                            @endif
                        </details>
                    @endforeach
                @endif
            </div>
        @endforeach
    @endif
</x-filament-panels::page>
