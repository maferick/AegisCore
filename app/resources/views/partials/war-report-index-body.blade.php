@php
    $fmtIsk = function (float $v): string {
        if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
        if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
        if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
        return number_format($v, 0);
    };
    $fmtNum = fn ($n) => number_format((int) $n);
    $linkBase = $public_routes ?? false ? '/war-report/' : '/portal/war-report/';
@endphp

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(360px, 1fr)); gap:1rem;">
    @foreach ($conflicts as $c)
        @php
            $tint = $c['opposing_tint'];
            $href = $linkBase . $c['key'];
            $totals = $c['totals'];
        @endphp
        <a href="{{ $href }}" class="aegis-conflict-card" style="--tint: {{ $tint }};">
            <div class="aegis-conflict-card-inner">
                <div style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.12em; margin-bottom:0.4rem;">Active conflict</div>
                <h2 style="margin:0 0 0.4rem 0; font-size:1.4rem; color:#e5e5e7; font-weight:700; letter-spacing:0.02em;">
                    @php
                        $label = $c['label'];
                        [$leftRaw, $rightRaw] = array_pad(array_map('trim', explode(' vs ', $label, 2)), 2, '');
                        $colorOf = fn (string $name) => $name === 'WinterCo' ? '#86efac' : $tint;
                    @endphp
                    <span style="color:{{ $colorOf($leftRaw) }};">{{ $leftRaw }}</span>
                    <span style="color:#7a7a82; font-weight:400;"> vs </span>
                    <span style="color:{{ $colorOf($rightRaw) }};">{{ $rightRaw }}</span>
                </h2>
                @if ($c['has_data'])
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.4rem; margin-top:0.7rem;">
                        <div style="padding:0.45rem 0.6rem; border:1px solid rgba(255,255,255,0.08); border-radius:5px; background:rgba(0,0,0,0.20);">
                            <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em;">Total killmails</div>
                            <div style="font-size:1.15rem; color:#e5e5e7; font-weight:600;">{{ $fmtNum($totals['wc']['kms'] + $totals['op']['kms']) }}</div>
                        </div>
                        <div style="padding:0.45rem 0.6rem; border:1px solid rgba(255,255,255,0.08); border-radius:5px; background:rgba(0,0,0,0.20);">
                            <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em;">ISK destroyed</div>
                            <div style="font-size:1.15rem; color:#fde68a; font-weight:600;">{{ $fmtIsk((float) ($totals['wc']['isk'] + $totals['op']['isk'])) }}</div>
                        </div>
                        <div style="padding:0.4rem 0.6rem;">
                            <div style="font-size:0.55rem; color:#86efac; text-transform:uppercase; letter-spacing:0.06em;">WinterCo losses</div>
                            <div style="font-size:0.85rem; color:#cbd5e1;">{{ $fmtNum($totals['wc']['kms']) }} <span style="color:#fde68a; font-size:0.7rem;">· {{ $fmtIsk((float) $totals['wc']['isk']) }}</span></div>
                        </div>
                        <div style="padding:0.4rem 0.6rem;">
                            <div style="font-size:0.55rem; color:{{ $tint }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $c['opposing_label'] }} losses</div>
                            <div style="font-size:0.85rem; color:#cbd5e1;">{{ $fmtNum($totals['op']['kms']) }} <span style="color:#fde68a; font-size:0.7rem;">· {{ $fmtIsk((float) $totals['op']['isk']) }}</span></div>
                        </div>
                    </div>
                @else
                    <p style="font-size:0.7rem; color:#7a7a82; font-style:italic; margin-top:0.5rem;">First load — totals populate after the next cache warm cycle.</p>
                @endif
                <div style="margin-top:0.8rem; font-size:0.65rem; color:{{ $tint }}; text-transform:uppercase; letter-spacing:0.08em;">Open report →</div>
            </div>
        </a>
    @endforeach
</div>

<style>
    .aegis-conflict-card {
        display: block;
        text-decoration: none;
        color: inherit;
        border-radius: 10px;
        padding: 1px;
        background: linear-gradient(135deg, rgba(34,197,94,0.10) 0%, rgba(0,0,0,0.45) 50%, color-mix(in srgb, var(--tint) 30%, transparent) 100%);
        border: 1px solid rgba(255, 255, 255, 0.10);
        box-shadow: 0 0 24px rgba(0,0,0,0.35), 0 0 32px color-mix(in srgb, var(--tint) 10%, transparent);
        transition: box-shadow 0.25s, transform 0.15s, border-color 0.2s;
    }
    .aegis-conflict-card:hover {
        border-color: color-mix(in srgb, var(--tint) 60%, transparent);
        box-shadow: 0 0 32px rgba(0,0,0,0.4), 0 0 56px color-mix(in srgb, var(--tint) 35%, transparent);
        transform: translateY(-1px);
    }
    .aegis-conflict-card-inner {
        padding: 1.25rem 1.5rem;
        background: rgba(8, 10, 14, 0.6);
        border-radius: 9px;
    }
</style>
