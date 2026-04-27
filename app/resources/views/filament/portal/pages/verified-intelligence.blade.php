<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        @php
            $kindLabels = [
                'pinned_incident' => 'pinned incident',
                'curated_summary' => 'curated summary',
                'strategic_event' => 'strategic event',
                'analyst_note' => 'analyst note',
                'narrative_override' => 'narrative override',
            ];
            $sigColors = ['low' => '#9ca3af', 'medium' => '#7dd3fc', 'high' => '#fdba74', 'coalition_level' => '#fb7185'];
        @endphp

        {{-- Filters --}}
        <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <div style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap; font-size:0.7rem;">
                <span style="color:#7a7a82;">kind:</span>
                <a href="?" style="padding:3px 8px; border-radius:4px; text-decoration:none; background:{{ $kind_filter === '' ? 'rgba(125,211,252,0.15)' : 'rgba(255,255,255,0.04)' }}; color:{{ $kind_filter === '' ? '#7dd3fc' : '#9ca3af' }};">all</a>
                @foreach ($kindLabels as $k => $label)
                    @php $a = $k === $kind_filter; $cnt = $kind_counts[$k] ?? 0; @endphp
                    <a href="?kind={{ $k }}" style="padding:3px 8px; border-radius:4px; text-decoration:none; background:{{ $a ? 'rgba(125,211,252,0.15)' : 'rgba(255,255,255,0.04)' }}; color:{{ $a ? '#7dd3fc' : '#9ca3af' }};">{{ $label }} <span style="opacity:0.6;">({{ $cnt }})</span></a>
                @endforeach
                <span style="margin-left:0.6rem; color:#7a7a82;">significance:</span>
                @foreach (['low', 'medium', 'high', 'coalition_level'] as $sig)
                    @php $a = $sig === $sig_filter; @endphp
                    <a href="?sig={{ $sig }}" style="padding:3px 8px; border-radius:4px; text-decoration:none; background:{{ $a ? 'rgba(125,211,252,0.15)' : 'rgba(255,255,255,0.04)' }}; color:{{ $a ? '#7dd3fc' : ($sigColors[$sig] ?? '#9ca3af') }};">{{ $sig }}</a>
                @endforeach
                <span style="margin-left:0.6rem; color:#7a7a82;">|</span>
                <a href="?pinned=1" style="padding:3px 8px; border-radius:4px; text-decoration:none; background:{{ $pinned_only ? 'rgba(253,186,116,0.15)' : 'rgba(255,255,255,0.04)' }}; color:{{ $pinned_only ? '#fdba74' : '#9ca3af' }};">📌 pinned only</a>
            </div>
        </div>

        {{-- Create new --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7a7a82; margin:0 0 0.4rem;">Create verified item</h3>
            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:0.4rem; font-size:0.7rem;">
                <select wire:model="newKind" style="padding:0.3rem 0.5rem; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.10); border-radius:4px; color:#e5e5e7;">
                    @foreach ($kindLabels as $k => $l)
                        <option value="{{ $k }}">{{ $l }}</option>
                    @endforeach
                </select>
                <select wire:model="newSignificance" style="padding:0.3rem 0.5rem; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.10); border-radius:4px; color:#e5e5e7;">
                    @foreach (['low', 'medium', 'high', 'coalition_level'] as $s)
                        <option value="{{ $s }}">{{ $s }}</option>
                    @endforeach
                </select>
                <input type="number" wire:model="newRelatedIncidentId" placeholder="incident id (opt)" style="padding:0.3rem 0.5rem; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.10); border-radius:4px; color:#e5e5e7;">
                <input type="number" wire:model="newRelatedAlertId" placeholder="alert id (opt)" style="padding:0.3rem 0.5rem; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.10); border-radius:4px; color:#e5e5e7;">
            </div>
            <input type="text" wire:model="newTitle" placeholder="title" style="margin-top:0.4rem; width:100%; padding:0.4rem 0.5rem; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.10); border-radius:4px; color:#e5e5e7; font-size:0.8rem;">
            <textarea wire:model="newBody" placeholder="markdown body" rows="3" style="margin-top:0.4rem; width:100%; padding:0.4rem 0.5rem; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.10); border-radius:4px; color:#e5e5e7; font-size:0.75rem;"></textarea>
            <button wire:click="create" style="margin-top:0.4rem; padding:0.4rem 0.9rem; background:rgba(134,239,172,0.15); color:#86efac; border:none; border-radius:4px; cursor:pointer; font-size:0.75rem;">save</button>
        </div>

        {{-- Items --}}
        @if (count($items) === 0)
            <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                 style="font-size:0.8rem; color:#cbd5e1; line-height:1.55;">
                <div style="font-size:0.75rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.4rem;">
                    Verified intelligence layer
                </div>
                <p style="margin:0 0 0.6rem;">
                    No verified items match the current filter. This is where you pin
                    operator-validated findings — confirmed alts, hostile patterns, doctrines
                    worth tracking — so they survive across calibration cycles. Use the form
                    above to add the first one, or jump to a surface that often produces
                    pinnable findings.
                </p>
                <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                    <a href="/portal/intelligence/alerts"
                       style="text-decoration:none; padding:6px 12px; background:rgba(125,211,252,0.12); color:#7dd3fc; border:1px solid rgba(125,211,252,0.25); border-radius:5px; font-size:0.75rem;">
                        Strategic alerts →
                    </a>
                    <a href="/portal/operations/incidents"
                       style="text-decoration:none; padding:6px 12px; background:rgba(165,180,252,0.10); color:#a5b4fc; border:1px solid rgba(165,180,252,0.25); border-radius:5px; font-size:0.75rem;">
                        Operational incidents →
                    </a>
                </div>
            </div>
        @else
            <div style="display:grid; gap:0.4rem;">
                @foreach ($items as $i)
                    @php $sigCol = $sigColors[$i->strategic_significance] ?? '#9ca3af'; @endphp
                    <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="border-left:3px solid {{ $sigCol }};">
                        <div style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap;">
                            @if ($i->pinned)
                                <span style="font-size:0.7rem; color:#fdba74;">📌</span>
                            @endif
                            <span style="font-size:0.55rem; color:{{ $sigCol }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $i->strategic_significance }}</span>
                            <span style="font-size:0.55rem; color:#a5b4fc; text-transform:uppercase; letter-spacing:0.06em;">{{ $kindLabels[$i->item_kind] ?? $i->item_kind }}</span>
                            <span style="font-size:0.85rem; color:#e5e5e7; flex:1;">{{ $i->title }}</span>
                            @if ($i->published)
                                <span style="font-size:0.55rem; color:#86efac;">published</span>
                            @endif
                            <x-intel-freshness surface="verified"
                                :timestamp="$i->verified_at ?? $i->created_at"
                                :persisted="$i->freshness_state ?? null" />
                            <span style="font-size:0.55rem; color:#9ca3af;"><x-relative-time :ts="$i->created_at" /></span>
                        </div>
                        @if ($i->body_md)
                            <x-aegis-md :body="$i->body_md" />
                        @endif
                        <div style="display:flex; gap:0.4rem; margin-top:0.3rem; font-size:0.6rem; color:#7a7a82;">
                            @if ($i->related_incident_id)
                                <a href="/portal/operations/incidents/{{ $i->related_incident_id }}" style="color:#c4b5fd; text-decoration:none;">incident #{{ $i->related_incident_id }} →</a>
                            @endif
                            @if ($i->related_alert_id)
                                <a href="/portal/intelligence/alerts" style="color:#fdba74; text-decoration:none;">alert #{{ $i->related_alert_id }}</a>
                            @endif
                            @if ($i->verified_at)
                                <span style="margin-left:auto;">verified {{ $i->verified_at }}</span>
                            @endif
                            <button wire:click="togglePin({{ $i->id }})" style="font-size:0.55rem; padding:1px 6px; border-radius:3px; background:rgba(253,186,116,0.10); color:#fdba74; border:none; cursor:pointer;">{{ $i->pinned ? 'unpin' : 'pin' }}</button>
                            @if (! $i->published)
                                <button wire:click="publish({{ $i->id }})" style="font-size:0.55rem; padding:1px 6px; border-radius:3px; background:rgba(134,239,172,0.10); color:#86efac; border:none; cursor:pointer;">publish</button>
                            @endif
                            <button wire:click="delete({{ $i->id }})" wire:confirm="Delete this verified item?" style="font-size:0.55rem; padding:1px 6px; border-radius:3px; background:rgba(252,165,165,0.10); color:#fca5a5; border:none; cursor:pointer;">delete</button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</x-filament-panels::page>
