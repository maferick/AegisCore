<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No bloc resolved for your account — link an ESI character whose alliance is tagged to a coalition bloc to see the Counter-Intel overview.
            </p>
        </div>
    @else
        @php
            $bandColors = [
                'critical' => '#fca5a5',
                'high' => '#fdba74',
                'elevated' => '#fde68a',
                'note_only' => '#bfdbfe',
                'clean' => '#86efac',
                'insufficient_history' => '#9ca3af',
            ];
            $statusColors = [
                'watching'  => '#fde68a',
                'escalated' => '#fca5a5',
                'cleared'   => '#86efac',
                'archived'  => '#9ca3af',
            ];
        @endphp

        {{-- Header --}}
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <div style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
                <h2 style="margin:0; font-size:1.05rem; color:#e5e5e7;">{{ $viewer_bloc_name }} <span style="font-weight:400; color:#7a7a82; font-size:0.75rem;">· bloc {{ $viewer_bloc_id }}</span></h2>
                <span style="font-size:0.6rem; color:#7a7a82; margin-left:auto; font-style:italic;">
                    advisory intelligence · review aid only · uncalibrated
                </span>
            </div>
            <p style="font-size:0.78rem; color:#9ca3af; margin-top:0.5rem; margin-bottom:0;">
                Bloc-scoped review queue, recent escalations, and signal distribution.
                All evidence is per-character on the
                <a href="/portal/characters/lookup" style="color:#c7d2fe;">character lookup</a>;
                this page never proposes punitive action.
            </p>
        </div>

        {{-- Top stats row --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.75rem; margin-bottom:1rem;">
            @foreach (['critical', 'high', 'elevated', 'note_only', 'clean'] as $band)
                @php $n = $band_dist[$band] ?? 0; @endphp
                <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="text-align:center;">
                    <div style="font-size:0.6rem; text-transform:uppercase; letter-spacing:0.08em; color:{{ $bandColors[$band] ?? '#9ca3af' }};">{{ str_replace('_', ' ', $band) }}</div>
                    <div style="font-size:1.5rem; font-weight:600; color:#e5e5e7; margin-top:0.2rem;">{{ number_format($n) }}</div>
                    <div style="font-size:0.55rem; color:#6b7280; margin-top:0.1rem;">last 24h renders</div>
                </div>
            @endforeach
        </div>

        {{-- Two-column layout: top rows + watchlist+escalations --}}
        <div style="display:grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap:1rem; margin-bottom:1rem;">
            {{-- Top review priority --}}
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin:0 0 0.5rem;">
                    Top review priority · last 7 days
                    <span style="text-transform:none; letter-spacing:0; color:#6b7280; font-style:italic; font-weight:400;">
                        — {{ count($top_rows) }} pilots in critical / high / elevated bands
                    </span>
                </h3>
                @if (count($top_rows) === 0)
                    <p style="font-size:0.78rem; color:#7a7a82; font-style:italic;">No flagged pilots in the last 7 days.</p>
                @else
                    <div style="overflow:auto; max-height:520px;">
                        <table style="width:100%; border-collapse:collapse; font-size:0.78rem;">
                            <thead>
                                <tr style="position:sticky; top:0; background:rgba(255,255,255,0.03); color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em; font-size:0.55rem; z-index:1;">
                                    <th style="text-align:left; padding:0.5rem 0.6rem;">Character</th>
                                    <th style="text-align:left; padding:0.5rem 0.6rem;">Band</th>
                                    <th style="text-align:left; padding:0.5rem 0.6rem;">Conf</th>
                                    <th style="text-align:right; padding:0.5rem 0.6rem;">F/N</th>
                                    <th style="text-align:left; padding:0.5rem 0.6rem;">In bloc</th>
                                    <th style="text-align:left; padding:0.5rem 0.6rem;">Watch</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($top_rows as $r)
                                    <tr style="border-top:1px solid rgba(255,255,255,0.04);">
                                        <td style="padding:0.4rem 0.6rem;">
                                            <a href="/portal/characters/lookup?cid={{ $r->character_id }}" style="color:#e5e5e7; text-decoration:none; display:flex; gap:0.4rem; align-items:center;">
                                                <img src="https://images.evetech.net/characters/{{ $r->character_id }}/portrait?size=32" referrerpolicy="no-referrer" style="width:20px;height:20px;border-radius:50%;" alt="">
                                                {{ $r->character_name ?? '#'.$r->character_id }}
                                            </a>
                                        </td>
                                        <td style="padding:0.4rem 0.6rem; color:{{ $bandColors[$r->rendered_band] ?? '#9ca3af' }}; text-transform:uppercase; letter-spacing:0.06em; font-size:0.6rem;">
                                            {{ $r->rendered_band }}
                                        </td>
                                        <td style="padding:0.4rem 0.6rem; color:#9ca3af; font-size:0.7rem;">
                                            {{ $r->confidence }}
                                        </td>
                                        <td style="padding:0.4rem 0.6rem; text-align:right; color:#cbd5e1;">
                                            {{ $r->flag_count }} / {{ $r->note_count }}
                                        </td>
                                        <td style="padding:0.4rem 0.6rem; color:{{ $r->declared_in_bloc ? '#86efac' : '#7a7a82' }}; font-size:0.7rem;">
                                            {{ $r->declared_in_bloc ? 'yes' : 'no' }}
                                        </td>
                                        <td style="padding:0.4rem 0.6rem;">
                                            @if ($r->watchlist_status)
                                                <span style="font-size:0.55rem; color:{{ $statusColors[$r->watchlist_status] ?? '#9ca3af' }}; text-transform:uppercase; letter-spacing:0.06em;">
                                                    {{ $r->watchlist_status }}
                                                </span>
                                            @else
                                                <span style="font-size:0.6rem; color:#6b7280;">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div style="display:grid; gap:0.75rem;">
                {{-- Watchlist counts --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin:0 0 0.4rem;">
                        Watchlist <a href="/portal/counter-intel/watchlist" style="color:#c7d2fe; text-transform:none; letter-spacing:0; font-size:0.7rem; font-style:italic; font-weight:400;">manage →</a>
                    </h3>
                    <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:0.4rem;">
                        @foreach (['watching', 'escalated', 'cleared', 'archived'] as $st)
                            @php $n = $watchlist_counts[$st] ?? 0; @endphp
                            <div style="padding:0.4rem 0.6rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:5px; display:flex; justify-content:space-between; align-items:center;">
                                <span style="font-size:0.6rem; color:{{ $statusColors[$st] ?? '#9ca3af' }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $st }}</span>
                                <span style="font-size:0.95rem; color:#e5e5e7; font-weight:600;">{{ $n }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Recent escalations --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin:0 0 0.4rem;">
                        Recent escalations · last 7 days
                    </h3>
                    @if (count($escalations) === 0)
                        <p style="font-size:0.72rem; color:#7a7a82; font-style:italic;">No escalations in this window.</p>
                    @else
                        <div style="display:grid; gap:0.3rem; max-height:280px; overflow:auto;">
                            @foreach ($escalations as $e)
                                <a href="/portal/characters/lookup?cid={{ $e->character_id }}" style="text-decoration:none; padding:0.35rem 0.5rem; background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.20); border-radius:5px; display:flex; gap:0.4rem; align-items:center; color:#e5e5e7; font-size:0.72rem;">
                                    <img src="https://images.evetech.net/characters/{{ $e->character_id }}/portrait?size=32" referrerpolicy="no-referrer" style="width:18px;height:18px;border-radius:50%;" alt="">
                                    <span style="flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $e->character_name ?? '#'.$e->character_id }}</span>
                                    <span style="font-size:0.55rem; color:#7a7a82;">{{ \Carbon\Carbon::parse($e->last_status_change_at)->diffForHumans() }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Top hostile triangles --}}
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin:0 0 0.4rem;">
                        Top hostile triangles
                    </h3>
                    @if (count($top_triangles) === 0)
                        <p style="font-size:0.72rem; color:#7a7a82; font-style:italic;">No triangles computed yet.</p>
                    @else
                        <div style="display:grid; gap:0.3rem; max-height:280px; overflow:auto;">
                            @foreach ($top_triangles as $t)
                                <a href="/portal/characters/lookup?cid={{ $t->character_id }}" style="text-decoration:none; padding:0.35rem 0.5rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:5px; display:flex; gap:0.4rem; align-items:center; color:#e5e5e7; font-size:0.72rem;">
                                    <span style="flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $t->character_name ?? '#'.$t->character_id }}</span>
                                    <span style="font-size:0.55rem; color:#fca5a5;">{{ $t->triangle_size }}× cluster</span>
                                    <span style="font-size:0.55rem; color:#7a7a82;">{{ $t->shared_battle_days }}d shared</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Phase 4 — operational timeline + active fleets --}}
        @if (! empty($recent_timeline) || ! empty($active_fleets))
            <div style="display:grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap:1rem; margin-bottom:1rem;">
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin:0 0 0.5rem;">
                        Operational timeline · last 24h
                        <span style="text-transform:none; letter-spacing:0; color:#6b7280; font-style:italic; font-weight:400;">
                            — log-derived events from uploaded streams
                        </span>
                    </h3>
                    @if (count($recent_timeline) === 0)
                        <p style="font-size:0.78rem; color:#7a7a82; font-style:italic;">No timeline events yet — uploader telemetry pending.</p>
                    @else
                        <div style="display:grid; gap:0.3rem; max-height:400px; overflow:auto;">
                            @foreach ($recent_timeline as $te)
                                @php
                                    $tColors = [
                                        'fleet_formup' => '#86efac',
                                        'hostile_report' => '#fca5a5',
                                        'escalation' => '#fda4af',
                                        'combat_spike' => '#fdba74',
                                        'self_destruct_wave' => '#c084fc',
                                        'extraction' => '#a5b4fc',
                                        'disengagement' => '#fde68a',
                                        'crash_symptom' => '#9ca3af',
                                        'intel_gap' => '#fb923c',
                                        'unknown' => '#6b7280',
                                    ];
                                    $tColor = $tColors[$te->timeline_type] ?? '#9ca3af';
                                @endphp
                                <div style="padding:0.4rem 0.55rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:5px; font-size:0.72rem;">
                                    <div style="display:flex; gap:0.4rem; align-items:center; margin-bottom:0.15rem;">
                                        <span style="font-size:0.55rem; color:{{ $tColor }}; text-transform:uppercase; letter-spacing:0.06em;">{{ str_replace('_', ' ', $te->timeline_type) }}</span>
                                        @if ($te->solar_system_name)
                                            <span style="font-size:0.6rem; color:#86efac;">{{ $te->solar_system_name }}</span>
                                        @endif
                                        @if ($te->source_listener)
                                            <span style="font-size:0.6rem; color:#9ca3af;">via {{ $te->source_listener }}</span>
                                        @endif
                                        <span style="font-size:0.55rem; color:#7a7a82; margin-left:auto;">{{ \Carbon\Carbon::parse($te->event_timestamp)->diffForHumans() }} · {{ $te->confidence }}</span>
                                    </div>
                                    <div style="color:#cbd5e1;">{{ $te->event_summary }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin:0 0 0.5rem;">
                        Recent fleet windows
                    </h3>
                    @if (count($active_fleets) === 0)
                        <p style="font-size:0.78rem; color:#7a7a82; font-style:italic;">No fleet windows in last 6h.</p>
                    @else
                        <div style="display:grid; gap:0.3rem; max-height:400px; overflow:auto;">
                            @foreach ($active_fleets as $fw)
                                @php
                                    $roleColors = [
                                        'fleet_lurker' => '#fde68a',
                                        'passive_observer' => '#fdba74',
                                        'active_combatant' => '#86efac',
                                        'logistics_presence' => '#a5b4fc',
                                        'scout_presence' => '#7dd3fc',
                                        'unknown' => '#9ca3af',
                                    ];
                                    $rc = $roleColors[$fw->derived_role] ?? '#9ca3af';
                                @endphp
                                <div style="padding:0.35rem 0.55rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:5px; font-size:0.7rem;">
                                    <div style="display:flex; gap:0.3rem; align-items:center;">
                                        <span style="color:#e5e5e7; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $fw->character_name }}</span>
                                        <span style="font-size:0.55rem; color:{{ $rc }}; text-transform:uppercase; letter-spacing:0.06em;">{{ str_replace('_', ' ', $fw->derived_role) }}</span>
                                    </div>
                                    <div style="font-size:0.6rem; color:#7a7a82;">
                                        {{ $fw->duration_minutes }}m · {{ $fw->spoken_messages }} msgs · {{ $fw->killmail_count }} km
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Reason distribution --}}
        @if (! empty($reason_counts))
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; color:#7a7a82; margin:0 0 0.5rem;">
                    Signal distribution · last 24h (top 1000 renders)
                </h3>
                @php $maxN = max($reason_counts); @endphp
                <div style="display:grid; gap:0.3rem;">
                    @foreach ($reason_counts as $reason => $n)
                        @php $pctW = $maxN > 0 ? ($n / $maxN * 100) : 0; @endphp
                        <div style="display:flex; gap:0.5rem; align-items:center; font-size:0.7rem;">
                            <span style="flex-shrink:0; width:170px; color:#cbd5e1;">{{ str_replace('_', ' ', $reason) }}</span>
                            <div style="flex:1; height:8px; background:rgba(255,255,255,0.04); border-radius:3px; overflow:hidden;">
                                <div style="width:{{ $pctW }}%; height:100%; background:rgba(165,180,252,0.55);"></div>
                            </div>
                            <span style="flex-shrink:0; width:50px; text-align:right; color:#9ca3af;">{{ number_format($n) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
