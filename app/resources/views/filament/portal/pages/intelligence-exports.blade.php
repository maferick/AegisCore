<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            $kindLabels = [
                'operational_report' => 'Operational report',
                'strategic_summary' => 'Strategic summary',
                'corridor_map' => 'Corridor map',
                'incident_timeline' => 'Incident timeline',
                'doctrine_evolution_report' => 'Doctrine evolution report',
            ];
        @endphp

        {{-- Generator --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Generate export</h3>
            <div style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap; font-size:0.7rem;">
                <label style="display:flex; gap:0.3rem; align-items:center; color:#cbd5e1;">
                    kind:
                    <select wire:model="kind" style="padding:0.3rem 0.5rem; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.12); border-radius:4px; color:#e5e5e7;">
                        @foreach ($kindLabels as $k => $l)
                            <option value="{{ $k }}">{{ $l }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="display:flex; gap:0.3rem; align-items:center; color:#cbd5e1;">
                    format:
                    <select wire:model="format" style="padding:0.3rem 0.5rem; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.12); border-radius:4px; color:#e5e5e7;">
                        <option value="markdown">markdown</option>
                        <option value="json">json</option>
                    </select>
                </label>
                <label style="display:flex; gap:0.3rem; align-items:center; color:#cbd5e1;">
                    days:
                    <input type="number" wire:model="days" min="1" max="90" style="width:60px; padding:0.3rem 0.5rem; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.12); border-radius:4px; color:#e5e5e7;">
                </label>
                <button wire:click="generate" style="padding:0.4rem 0.9rem; background:rgba(125,211,252,0.15); color:#7dd3fc; border:none; border-radius:4px; cursor:pointer;">generate</button>
            </div>
        </div>

        {{-- Recent --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Recent exports ({{ count($recent) }})</h3>
            @if (count($recent) === 0)
                <p style="font-size:0.75rem; color:#7a7a82; font-style:italic;">No exports yet.</p>
            @else
                <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                    <thead style="color:#7a7a82;">
                        <tr><th style="text-align:left;">title</th><th style="text-align:left;">format</th><th style="text-align:left;">created</th><th style="text-align:left;">expires</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $r)
                            <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                <td style="padding:3px 4px;">{{ $r->title }}</td>
                                <td style="padding:3px 4px; color:#a5b4fc;">{{ $r->format }}</td>
                                <td style="padding:3px 4px; color:#9ca3af; font-family:ui-monospace,monospace; font-size:0.6rem;">{{ $r->created_at }}</td>
                                <td style="padding:3px 4px; color:#9ca3af; font-family:ui-monospace,monospace; font-size:0.6rem;">{{ $r->expires_at }}</td>
                                <td style="padding:3px 4px; text-align:right;">
                                    <a href="/portal/intel/share/{{ $r->share_token }}" target="_blank" rel="noopener" style="color:#7dd3fc; text-decoration:none; font-size:0.6rem;">view →</a>
                                    <a href="/portal/intel/share/{{ $r->share_token }}?dl=1" style="color:#86efac; text-decoration:none; font-size:0.6rem; margin-left:0.4rem;">download</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</x-filament-panels::page>
