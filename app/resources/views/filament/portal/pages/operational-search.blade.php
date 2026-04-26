<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">No bloc resolved.</p>
        </div>
    @else
        <form method="GET" class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <input type="text" name="q" value="{{ $q }}" placeholder="search system, alliance, doctrine, ship, corridor, operator, battle id..." style="flex:1; padding:0.5rem 0.75rem; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.12); border-radius:5px; color:#e5e5e7; font-size:0.85rem;">
                <button type="submit" style="padding:0.5rem 1rem; background:rgba(125,211,252,0.15); color:#7dd3fc; border:none; border-radius:5px; cursor:pointer; font-size:0.8rem;">search</button>
            </div>
        </form>

        @if (! empty($empty))
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p style="font-size:0.8rem; color:#9ca3af;">Type a system name, alliance, doctrine, ship type, or character name to search across the operational corpus.</p>
            </div>
        @else
            @php
                $sections = [
                    ['Systems', $systems, '#86efac'],
                    ['Alliances', $alliances, '#a5b4fc'],
                    ['Doctrines', $doctrines, '#c4b5fd'],
                    ['Ship types', $ships, '#fdba74'],
                    ['Corridors', $corridors, '#7dd3fc'],
                    ['Operators', $operators, '#f9a8d4'],
                    ['Incidents', $incidents, '#fde68a'],
                    ['Battles', $battles, '#fb7185'],
                ];
                $totalHits = collect($sections)->sum(fn ($s) => count($s[1]));
            @endphp

            <div style="font-size:0.7rem; color:#9ca3af; margin-bottom:0.5rem;">{{ $totalHits }} hit(s) for <strong style="color:#e5e5e7;">"{{ $q }}"</strong>.</div>

            <div style="display:grid; gap:0.75rem;">
                @if (count($systems) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#86efac; margin:0 0 0.4rem;">Systems</h3>
                        <table style="width:100%; font-size:0.7rem; color:#cbd5e1; border-collapse:collapse;">
                            <thead style="color:#7a7a82;"><tr><th style="text-align:left;">system</th><th style="text-align:right;">incidents</th><th style="text-align:right;">strategic+</th><th style="text-align:left;">last seen</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($systems as $s)
                                    <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                        <td style="padding:3px 4px;">{{ $s->primary_system_name }}</td>
                                        <td style="text-align:right; padding:3px 4px;">{{ $s->incident_count }}</td>
                                        <td style="text-align:right; padding:3px 4px; color:{{ $s->strategic_count > 0 ? '#fdba74' : '#7a7a82' }};">{{ $s->strategic_count }}</td>
                                        <td style="padding:3px 4px; color:#9ca3af; font-family:ui-monospace,monospace; font-size:0.6rem;">{{ $s->last_seen }}</td>
                                        <td style="padding:3px 4px; text-align:right;"><a href="/portal/operations/heatmap?system={{ $s->primary_system_name }}" style="color:#7dd3fc; text-decoration:none; font-size:0.55rem;">heatmap →</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if (count($alliances) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#a5b4fc; margin:0 0 0.4rem;">Alliances</h3>
                        <div style="display:grid; gap:0.2rem;">
                            @foreach ($alliances as $a)
                                <div style="font-size:0.7rem; padding:0.25rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px; display:flex; gap:0.4rem;">
                                    <span style="flex:1;">{{ $a->alliance_name ?? '#'.$a->alliance_id }}</span>
                                    <span style="color:#a5b4fc;">{{ str_replace('_',' ',$a->operational_style) }}</span>
                                    <span style="color:#7a7a82;">{{ $a->incident_count }} inc · {{ $a->window_end }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (count($doctrines) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#c4b5fd; margin:0 0 0.4rem;">Doctrines</h3>
                        <div style="display:grid; gap:0.2rem;">
                            @foreach ($doctrines as $d)
                                <div style="font-size:0.7rem; padding:0.25rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px; display:flex; gap:0.3rem;">
                                    <span style="flex:1;">{{ $d->canonical_name }}</span>
                                    <span style="color:#7a7a82;">obs: {{ $d->observation_count }}</span>
                                    <span style="color:#c4b5fd;">conf: {{ number_format((float)$d->confidence, 2) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (count($ships) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#fdba74; margin:0 0 0.4rem;">Ship types</h3>
                        <div style="display:flex; gap:0.3rem; flex-wrap:wrap;">
                            @foreach ($ships as $s)
                                <span style="font-size:0.7rem; padding:3px 8px; background:rgba(253,186,116,0.08); color:#fdba74; border-radius:4px;">{{ $s->name }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (count($corridors) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#7dd3fc; margin:0 0 0.4rem;">Corridors</h3>
                        <div style="display:grid; gap:0.2rem;">
                            @foreach ($corridors as $c)
                                <div style="font-size:0.7rem; padding:0.25rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px; display:flex; gap:0.3rem;">
                                    <span style="flex:1; color:#86efac;">{{ $c->from_system_name }} → {{ $c->to_system_name }}</span>
                                    <span style="color:#a5b4fc;">{{ $c->route_classification }}</span>
                                    <span style="color:#7a7a82;">{{ $c->transition_count }} transits</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (count($operators) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#f9a8d4; margin:0 0 0.4rem;">Operators</h3>
                        <div style="display:grid; gap:0.2rem;">
                            @foreach ($operators as $o)
                                <a href="/portal/characters/lookup?cid={{ $o->character_id }}" style="font-size:0.7rem; padding:0.25rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px; display:flex; gap:0.4rem; text-decoration:none; color:#e5e5e7;">
                                    <img src="https://images.evetech.net/characters/{{ $o->character_id }}/portrait?size=32" style="width:18px; height:18px; border-radius:50%;" alt="">
                                    <span style="flex:1;">{{ $o->character_name ?? '#'.$o->character_id }}</span>
                                    <span style="color:#a5b4fc;">{{ str_replace('_',' ',$o->primary_style) }}</span>
                                    <span style="color:#7a7a82;">{{ $o->cluster_appearances }}× · {{ $o->window_end }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (count($incidents) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#fde68a; margin:0 0 0.4rem;">Incidents</h3>
                        <div style="display:grid; gap:0.2rem;">
                            @foreach ($incidents as $i)
                                <a href="/portal/operations/incidents/{{ $i->id }}" style="font-size:0.7rem; padding:0.25rem 0.4rem; background:rgba(255,255,255,0.02); border-radius:4px; display:block; text-decoration:none; color:#e5e5e7;">
                                    <div style="display:flex; gap:0.4rem; align-items:center;">
                                        <span style="font-size:0.55rem; color:#fde68a; text-transform:uppercase;">{{ $i->severity }}</span>
                                        <span style="color:#86efac;">{{ $i->primary_system_name }}</span>
                                        <span style="margin-left:auto; color:#7a7a82; font-size:0.6rem;">{{ $i->start_at }}</span>
                                    </div>
                                    @if ($i->timeline_summary)
                                        <div style="font-size:0.65rem; color:#cbd5e1; margin-top:0.15rem;">{{ \Illuminate\Support\Str::limit($i->timeline_summary, 200) }}</div>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (count($battles) > 0)
                    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#fb7185; margin:0 0 0.4rem;">Battle theaters</h3>
                        @foreach ($battles as $b)
                            <a href="/portal/battles/theaters/{{ $b->id }}" style="font-size:0.75rem; color:#fdba74; text-decoration:none;">battle #{{ $b->id }} · {{ $b->start_time }} → {{ $b->end_time }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endif
</x-filament-panels::page>
