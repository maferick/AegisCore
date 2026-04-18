@php
    /** @var array $d */
    /** @var array $tab */
    /** @var callable $confBand */
    [$bandName, $bandColor] = $confBand($d['confidence']);
    $sharePct = isset($d['share']) ? (int) round($d['share'] * 100) : null;
@endphp
<div style="border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); border-radius:6px; padding:0.75rem;">
    <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem;">
        <img src="https://images.evetech.net/types/{{ $d['hull_type_id'] }}/icon?size=32"
             style="width:32px;height:32px;border-radius:3px;" alt="">
        <div style="flex:1; min-width:0;">
            <div style="font-weight:500; color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                {{ $d['hull_name'] }}
            </div>
            <div style="font-size:0.7rem; color:#7a7a82;">
                {{ $tab['label'] }}: {{ $d['scope_n'] }}× · global: {{ $d['global_n'] }}×
                @if ($sharePct !== null)
                    · <span style="color:#cbd5e1;" title="scope share of global adoption">{{ $sharePct }}% share</span>
                @endif
                · <span style="color: {{ $bandColor }};">{{ $bandName }}</span>
            </div>
        </div>
    </div>

    <details>
        <summary style="font-size:0.75rem; color:#9ca3af; cursor:pointer;">
            {{ count($d['modules']) }} module(s) · fit
        </summary>
        <div style="margin-top:0.4rem;">
            @foreach ($d['modules'] as $m)
                @php
                    $both = ! empty($m['global']) && ! empty($m['corp']);
                    $corpOnly = empty($m['global']) && ! empty($m['corp']);
                    if ($both)        [$nc, $bd, $bc] = ['#d1d5db', '✓', '#22c55e'];
                    elseif ($corpOnly)[$nc, $bd, $bc] = ['#fde047', 'you', '#fde047'];
                    else              [$nc, $bd, $bc] = ['#9ca3af', 'global', '#93c5fd'];
                @endphp
                <div style="display:flex; gap:0.4rem; align-items:center; font-size:0.75rem; padding:0.15rem 0;" @if(! empty($m['also_seen'])) title="also seen: @foreach($m['also_seen'] as $v){{ $v['name'] }} ({{ number_format($v['frequency']*100,0) }}%)@if(! $loop->last), @endif @endforeach" @endif>
                    <img src="https://images.evetech.net/types/{{ $m['type_id'] }}/icon?size=32"
                         style="width:16px;height:16px;border-radius:2px;" alt="">
                    <span style="flex:1; color: {{ $nc }}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        @if ($m['quantity'] > 1) {{ $m['quantity'] }}× @endif
                        {{ $m['name'] ?? ('type ' . $m['type_id']) }}
                        @if (! empty($m['also_seen']))
                            <span style="color:#7a7a82; font-size:0.85em; font-style:italic;"> · +{{ count($m['also_seen']) }} variant{{ count($m['also_seen']) > 1 ? 's' : '' }}</span>
                        @endif
                    </span>
                    <span style="font-size:0.6em; padding:1px 4px; border-radius:6px; border:1px solid {{ $bc }}; color: {{ $bc }};">{{ $bd }}</span>
                    <span style="color:#7a7a82; font-size:0.7em;">{{ $m['slot'] }}</span>
                </div>
            @endforeach
        </div>
    </details>

    <details style="margin-top:0.35rem;">
        <summary style="font-size:0.75rem; color:#9ca3af; cursor:pointer;">EFT export (copy-paste into Pyfa / fitting window)</summary>
        <textarea readonly rows="14"
                  onclick="this.select();document.execCommand('copy');"
                  style="width:100%; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size:0.72rem; background:#0b1218; color:#9eeaff; border:1px solid #13202a; border-radius:4px; padding:0.4rem; margin-top:0.3rem; resize:vertical;">{{ $d['eft'] }}</textarea>
    </details>

    <details style="margin-top:0.35rem;">
        <summary style="font-size:0.75rem; color:#9ca3af; cursor:pointer;">Buyall list</summary>
        <textarea readonly rows="{{ min(12, count($d['modules']) + 1) }}"
                  onclick="this.select();document.execCommand('copy');"
                  style="width:100%; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size:0.72rem; background:#0b1218; color:#fde68a; border:1px solid #13202a; border-radius:4px; padding:0.4rem; margin-top:0.3rem; resize:vertical;">{{ $d['buyall'] }}</textarea>
    </details>
</div>
