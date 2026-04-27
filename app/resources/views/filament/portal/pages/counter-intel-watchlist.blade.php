<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No bloc resolved for your account — link an ESI character with an alliance tagged to a coalition bloc to see its watchlist queue.
            </p>
        </div>
    @else
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
            <div style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
                <h2 style="margin:0; font-size:1.05rem; color:#e5e5e7;">{{ $viewer_bloc_name }} <span style="font-weight:400; color:#7a7a82; font-size:0.75rem;">· bloc {{ $viewer_bloc_id }}</span></h2>
                <span style="font-size:0.6rem; color:#7a7a82; margin-left:auto; font-style:italic;">
                    advisory intelligence · review aid only · uncalibrated
                </span>
            </div>

            <div style="margin-top:0.75rem; display:flex; gap:0.4rem; flex-wrap:wrap;">
                @foreach (['' => 'all', 'watching' => 'watching', 'escalated' => 'escalated', 'cleared' => 'cleared', 'archived' => 'archived'] as $val => $label)
                    @php
                        $count = $val === '' ? array_sum($counts) : ($counts[$val] ?? 0);
                        $isActive = (string) $status_filter === (string) $val;
                    @endphp
                    <a href="?status={{ $val }}"
                       style="font-size:0.65rem; padding:4px 10px; border-radius:4px; text-decoration:none; text-transform:uppercase; letter-spacing:0.06em;
                              background:{{ $isActive ? 'rgba(99,102,241,0.20)' : 'rgba(255,255,255,0.04)' }};
                              color:{{ $isActive ? '#c7d2fe' : '#9ca3af' }};
                              border:1px solid {{ $isActive ? 'rgba(99,102,241,0.40)' : 'rgba(255,255,255,0.10)' }};">
                        {{ $label }} <span style="opacity:0.7; margin-left:0.25rem;">{{ $count }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        @if (count($rows) === 0)
            <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                 style="font-size:0.8rem; color:#cbd5e1; line-height:1.55;">
                <div style="font-size:0.75rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.4rem;">
                    Empty queue
                </div>
                <p style="margin:0 0 0.6rem;">
                    No watchlist entries match this filter. Add a pilot from their lookup card,
                    or jump straight to the top review-priority candidates surfaced by Counter-Intel.
                </p>
                <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                    <a href="/portal/characters/lookup"
                       style="text-decoration:none; padding:6px 12px; background:rgba(125,211,252,0.12); color:#7dd3fc; border:1px solid rgba(125,211,252,0.25); border-radius:5px; font-size:0.75rem;">
                        Look up a pilot →
                    </a>
                    <a href="/portal/intelligence/counter-intel"
                       style="text-decoration:none; padding:6px 12px; background:rgba(165,180,252,0.10); color:#a5b4fc; border:1px solid rgba(165,180,252,0.25); border-radius:5px; font-size:0.75rem;">
                        Top review candidates →
                    </a>
                </div>
            </div>
        @else
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="overflow:hidden;">
                <table style="width:100%; border-collapse:collapse; font-size:0.78rem;">
                    <thead>
                        <tr style="background:rgba(255,255,255,0.03); color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em; font-size:0.6rem;">
                            <th style="text-align:left; padding:0.6rem 0.75rem;">Character</th>
                            <th style="text-align:left; padding:0.6rem 0.75rem;">Status</th>
                            <th style="text-align:left; padding:0.6rem 0.75rem;">P1 band</th>
                            <th style="text-align:left; padding:0.6rem 0.75rem;">Confidence</th>
                            <th style="text-align:right; padding:0.6rem 0.75rem;">Flags / Notes</th>
                            <th style="text-align:left; padding:0.6rem 0.75rem;">Reason</th>
                            <th style="text-align:left; padding:0.6rem 0.75rem;">Added by</th>
                            <th style="text-align:left; padding:0.6rem 0.75rem;">Last change</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            @php
                                $statusColors = [
                                    'watching'  => '#fde68a',
                                    'escalated' => '#fca5a5',
                                    'cleared'   => '#86efac',
                                    'archived'  => '#9ca3af',
                                ];
                                $bandColors = [
                                    'critical' => '#fca5a5',
                                    'high' => '#fdba74',
                                    'elevated' => '#fde68a',
                                    'note_only' => '#bfdbfe',
                                    'clean' => '#86efac',
                                ];
                            @endphp
                            <tr style="border-top:1px solid rgba(255,255,255,0.04);">
                                <td style="padding:0.55rem 0.75rem;">
                                    <a href="/portal/characters/lookup?cid={{ $r->character_id }}" style="color:#e5e5e7; text-decoration:none; display:flex; gap:0.4rem; align-items:center;">
                                        <img src="https://images.evetech.net/characters/{{ $r->character_id }}/portrait?size=32" referrerpolicy="no-referrer" style="width:24px;height:24px;border-radius:50%;" alt="">
                                        {{ $r->character_name ?? '#'.$r->character_id }}
                                    </a>
                                </td>
                                <td style="padding:0.55rem 0.75rem; color:{{ $statusColors[$r->status] ?? '#9ca3af' }}; text-transform:uppercase; letter-spacing:0.06em; font-size:0.6rem;">
                                    {{ $r->status }}
                                </td>
                                <td style="padding:0.55rem 0.75rem; color:{{ $bandColors[$r->rendered_band ?? ''] ?? '#7a7a82' }}; text-transform:uppercase; letter-spacing:0.06em; font-size:0.6rem;">
                                    {{ $r->rendered_band ?? '—' }}
                                </td>
                                <td style="padding:0.55rem 0.75rem; color:#9ca3af; font-size:0.7rem;">
                                    {{ $r->confidence ?? '—' }}
                                </td>
                                <td style="padding:0.55rem 0.75rem; text-align:right; color:#cbd5e1;">
                                    {{ $r->flag_count ?? 0 }} / {{ $r->note_count ?? 0 }}
                                </td>
                                <td style="padding:0.55rem 0.75rem; color:#cbd5e1; max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                    {{ $r->reason ?? '—' }}
                                </td>
                                <td style="padding:0.55rem 0.75rem; color:#9ca3af;">
                                    {{ $r->added_by_name ?? '—' }}
                                </td>
                                <td style="padding:0.55rem 0.75rem; color:#7a7a82; font-size:0.7rem;">
                                    {{ $r->last_status_change_at ? \Carbon\Carbon::parse($r->last_status_change_at)->diffForHumans() : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-filament-panels::page>
