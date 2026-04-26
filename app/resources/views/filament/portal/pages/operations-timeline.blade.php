<x-filament-panels::page>
    @if (! empty($no_bloc))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No bloc resolved for your account.
            </p>
        </div>
    @else
        @php
            $sevColors = [
                'noise' => '#6b7280',
                'tactical' => '#fde68a',
                'strategic' => '#fdba74',
                'escalation' => '#fca5a5',
                'coalition_level' => '#d8b4fe',
            ];
            $typeColors = [
                'fleet_op' => '#86efac',
                'engagement' => '#fda4af',
                'hostile_contact' => '#fdba74',
                'combat' => '#fca5a5',
                'disengagement' => '#fde68a',
                'telemetry_gap' => '#9ca3af',
                'mixed' => '#a5b4fc',
            ];
        @endphp

        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <div style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
                <h2 style="margin:0; font-size:1.05rem; color:#e5e5e7;">{{ $viewer_bloc_name }} <span style="font-weight:400; color:#7a7a82; font-size:0.75rem;">· operations timeline</span></h2>
                <span style="font-size:0.6rem; color:#7a7a82; margin-left:auto; font-style:italic;">advisory · log-derived · {{ $since_hours }}h window</span>
            </div>
            <p style="font-size:0.78rem; color:#9ca3af; margin-top:0.4rem; margin-bottom:0;">
                Operational incidents fused from intel reports, fleet activity, combat events, and battle correlations. Click an incident card to open the full dossier.
            </p>
        </div>

        {{-- Filters --}}
        <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <form method="get" style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap;">
                <span style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em;">severity</span>
                @foreach (['' => 'all', 'coalition_level' => 'coalition', 'escalation' => 'escalation', 'strategic' => 'strategic', 'tactical' => 'tactical', 'noise' => 'noise'] as $val => $label)
                    @php
                        $count = $val === '' ? array_sum($counts_by_severity) : ($counts_by_severity[$val] ?? 0);
                        $isActive = (string) $severity_filter === (string) $val;
                        $color = $sevColors[$val] ?? '#9ca3af';
                    @endphp
                    <a href="?severity={{ $val }}{{ $type_filter ? '&type='.$type_filter : '' }}{{ $system_filter ? '&system='.$system_filter : '' }}&since_hours={{ $since_hours }}{{ $linked_only ? '&linked_only=1' : '' }}"
                       style="font-size:0.6rem; padding:3px 8px; border-radius:4px; text-decoration:none; text-transform:uppercase; letter-spacing:0.06em;
                              background:{{ $isActive ? 'rgba(99,102,241,0.20)' : 'rgba(255,255,255,0.04)' }};
                              color:{{ $isActive ? '#c7d2fe' : $color }};
                              border:1px solid {{ $isActive ? 'rgba(99,102,241,0.40)' : 'rgba(255,255,255,0.10)' }};">
                        {{ $label }} <span style="opacity:0.7; margin-left:3px;">{{ $count }}</span>
                    </a>
                @endforeach

                <span style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em; margin-left:0.6rem;">type</span>
                @foreach (['' => 'all', 'fleet_op', 'engagement', 'hostile_contact', 'combat', 'disengagement', 'telemetry_gap'] as $idx => $val)
                    @php
                        $val = is_int($idx) ? $val : $idx;
                        $label = is_int($idx) ? str_replace('_', ' ', $val) : 'all';
                        $count = $val === '' ? array_sum($counts_by_type) : ($counts_by_type[$val] ?? 0);
                        $isActive = (string) $type_filter === (string) $val;
                        $color = $typeColors[$val] ?? '#9ca3af';
                    @endphp
                    @if ($count > 0 || $val === '')
                        <a href="?severity={{ $severity_filter }}&type={{ $val }}{{ $system_filter ? '&system='.$system_filter : '' }}&since_hours={{ $since_hours }}{{ $linked_only ? '&linked_only=1' : '' }}"
                           style="font-size:0.6rem; padding:3px 8px; border-radius:4px; text-decoration:none; text-transform:uppercase; letter-spacing:0.06em;
                                  background:{{ $isActive ? 'rgba(99,102,241,0.20)' : 'rgba(255,255,255,0.04)' }};
                                  color:{{ $isActive ? '#c7d2fe' : $color }};
                                  border:1px solid {{ $isActive ? 'rgba(99,102,241,0.40)' : 'rgba(255,255,255,0.10)' }};">
                            {{ $label }} <span style="opacity:0.7; margin-left:3px;">{{ $count }}</span>
                        </a>
                    @endif
                @endforeach

                <input type="text" name="system" value="{{ $system_filter }}" placeholder="system substring"
                       style="font-size:0.7rem; padding:3px 8px; border-radius:4px; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.10); color:#e5e5e7; margin-left:0.4rem; width:140px;">
                <select name="since_hours"
                        style="font-size:0.7rem; padding:3px 8px; border-radius:4px; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.10); color:#e5e5e7;">
                    @foreach ([24 => '24h', 168 => '7d', 720 => '30d', 4320 => '180d', 8760 => '365d'] as $h => $lbl)
                        <option value="{{ $h }}" {{ (int) $since_hours === $h ? 'selected' : '' }}>{{ $lbl }}</option>
                    @endforeach
                </select>
                <label style="font-size:0.65rem; color:#9ca3af; display:flex; gap:0.3rem; align-items:center;">
                    <input type="checkbox" name="linked_only" value="1" {{ $linked_only ? 'checked' : '' }}> linked battles only
                </label>
                <input type="hidden" name="severity" value="{{ $severity_filter }}">
                <input type="hidden" name="type" value="{{ $type_filter }}">
                <button type="submit" style="font-size:0.65rem; padding:4px 10px; border-radius:4px; background:rgba(99,102,241,0.15); color:#c7d2fe; border:1px solid rgba(99,102,241,0.30);">apply</button>
            </form>
        </div>

        @if (count($rows) === 0)
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p style="font-size:0.8rem; color:#9ca3af;">No incidents match this filter.</p>
            </div>
        @else
            <div style="display:grid; gap:0.5rem;">
                @foreach ($rows as $r)
                    @php
                        $sevColor = $sevColors[$r->severity] ?? '#9ca3af';
                        $typeColor = $typeColors[$r->incident_type] ?? '#9ca3af';
                        $signals = json_decode($r->signal_types_json ?? '[]', true) ?: [];
                        $duration = max(1, (int) ((\Carbon\Carbon::parse($r->end_at)->timestamp - \Carbon\Carbon::parse($r->start_at)->timestamp) / 60));
                    @endphp
                    <a href="/portal/operations/incidents/{{ $r->id }}" style="text-decoration:none; color:inherit;">
                        <div style="padding:0.7rem 0.9rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:6px; border-left:4px solid {{ $sevColor }};">
                            <div style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap; margin-bottom:0.25rem;">
                                <span style="font-size:0.6rem; color:{{ $sevColor }}; text-transform:uppercase; letter-spacing:0.08em;">{{ str_replace('_', ' ', $r->severity) }}</span>
                                <span style="font-size:0.6rem; color:{{ $typeColor }}; text-transform:uppercase; letter-spacing:0.08em;">{{ str_replace('_', ' ', $r->incident_type) }}</span>
                                @if ($r->primary_system_name)
                                    <span style="font-size:0.7rem; color:#86efac;">{{ $r->primary_system_name }}</span>
                                @endif
                                <span style="font-size:0.6rem; color:#9ca3af;">{{ $duration }}m</span>
                                @if ($r->battle_id)
                                    <span style="font-size:0.55rem; color:#fdba74; padding:1px 6px; border-radius:3px; background:rgba(249,115,22,0.10);">battle #{{ $r->battle_id }}</span>
                                @endif
                                @if ($r->participant_estimate)
                                    <span style="font-size:0.55rem; color:#9ca3af;">~{{ $r->participant_estimate }} named</span>
                                @endif
                                <span style="font-size:0.55rem; color:#7a7a82; margin-left:auto;">{{ \Carbon\Carbon::parse($r->start_at)->diffForHumans() }}</span>
                            </div>
                            <div style="font-size:0.78rem; color:#cbd5e1; line-height:1.35;">{{ $r->timeline_summary }}</div>
                            <div style="margin-top:0.3rem; display:flex; gap:0.3rem; flex-wrap:wrap;">
                                @foreach ($signals as $sig)
                                    <span style="font-size:0.55rem; color:#9ca3af; padding:1px 6px; border-radius:3px; background:rgba(255,255,255,0.04); text-transform:uppercase; letter-spacing:0.06em;">{{ str_replace('_', ' ', $sig) }}</span>
                                @endforeach
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    @endif
</x-filament-panels::page>
