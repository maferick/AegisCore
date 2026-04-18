@php
    /** @var array{sideA: array, sideB: array} $sides */
@endphp
<div style="display:flex; align-items:center; gap:8px; font-size:0.7rem;">
    <div style="display:flex; gap:4px;">
        @foreach ($sides['sideA'] as $a)
            <span style="display:inline-flex; align-items:center; gap:3px;" title="{{ $a['name'] }} ({{ $a['pilots'] }} pilots)">
                <img src="https://images.evetech.net/alliances/{{ $a['alliance_id'] }}/logo?size=32"
                     referrerpolicy="no-referrer"
                     style="width:20px;height:20px;border-radius:3px;border:1px solid rgba(59,130,246,0.4);"
                     alt="">
                <span style="color:#93c5fd;">{{ $a['pilots'] }}</span>
            </span>
        @endforeach
    </div>
    @if (! empty($sides['sideB']))
        <span style="color:#7a7a82;">vs</span>
        <div style="display:flex; gap:4px;">
            @foreach ($sides['sideB'] as $a)
                <span style="display:inline-flex; align-items:center; gap:3px;" title="{{ $a['name'] }} ({{ $a['pilots'] }} pilots)">
                    <img src="https://images.evetech.net/alliances/{{ $a['alliance_id'] }}/logo?size=32"
                         referrerpolicy="no-referrer"
                         style="width:20px;height:20px;border-radius:3px;border:1px solid rgba(248,113,113,0.4);"
                         alt="">
                    <span style="color:#fca5a5;">{{ $a['pilots'] }}</span>
                </span>
            @endforeach
        </div>
    @endif
</div>
