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
                        $colorOf = fn (string $name) => $name === 'WinterCo' ? '#6dd6ff' : $tint;
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
                            <div style="font-size:1.15rem; color:#f4c75c; font-weight:600;">{{ $fmtIsk((float) ($totals['wc']['isk'] + $totals['op']['isk'])) }}</div>
                        </div>
                        <div style="padding:0.4rem 0.6rem;">
                            <div style="font-size:0.55rem; color:#6dd6ff; text-transform:uppercase; letter-spacing:0.06em;">WinterCo losses</div>
                            <div style="font-size:0.85rem; color:#cbd5e1;">{{ $fmtNum($totals['wc']['kms']) }} <span style="color:#f4c75c; font-size:0.7rem;">· {{ $fmtIsk((float) $totals['wc']['isk']) }}</span></div>
                        </div>
                        <div style="padding:0.4rem 0.6rem;">
                            <div style="font-size:0.55rem; color:{{ $tint }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $c['opposing_label'] }} losses</div>
                            <div style="font-size:0.85rem; color:#cbd5e1;">{{ $fmtNum($totals['op']['kms']) }} <span style="color:#f4c75c; font-size:0.7rem;">· {{ $fmtIsk((float) $totals['op']['isk']) }}</span></div>
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
    /* Conflict cards — HUD console panels with corner brackets,
       hairline cyan border, faction-tinted glow on hover. Match the
       war-report .km-card chrome so the homepage feels like the
       same console. */
    .aegis-conflict-card {
        display: block;
        text-decoration: none;
        color: inherit;
        position: relative;
        border-radius: 0;
        padding: 0;
        background:
            /* 4 corner L-brackets in cyan */
            linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 16px 1px no-repeat,
            linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 1px 16px no-repeat,
            linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 0 / 16px 1px no-repeat,
            linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 0 / 1px 16px no-repeat,
            linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 100% / 16px 1px no-repeat,
            linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 calc(100% - 16px) / 1px 16px no-repeat,
            linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 100% / 16px 1px no-repeat,
            linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% calc(100% - 16px) / 1px 16px no-repeat,
            linear-gradient(135deg,
                rgba(109,214,255,0.06) 0%,
                rgba(8,12,22,0.85) 50%,
                color-mix(in srgb, var(--tint) 20%, transparent) 100%);
        border: 1px solid rgba(109,214,255,0.10);
        box-shadow:
            0 0 18px rgba(0,0,0,0.50),
            0 0 28px color-mix(in srgb, var(--tint) 8%, transparent);
        transition: box-shadow 0.25s, transform 0.15s, border-color 0.2s;
    }
    .aegis-conflict-card::before {
        /* Gold accent strip across the top edge — single rare cue. */
        content: '';
        position: absolute;
        top: 0; left: 22%; right: 22%;
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--hud-gold), transparent);
        opacity: 0.75;
        pointer-events: none;
        z-index: 1;
    }
    .aegis-conflict-card:hover {
        border-color: color-mix(in srgb, var(--tint) 60%, transparent);
        box-shadow:
            0 0 24px rgba(0,0,0,0.45),
            0 0 48px color-mix(in srgb, var(--tint) 35%, transparent);
        transform: translateY(-1px);
    }
    .aegis-conflict-card-inner {
        padding: 1.25rem 1.5rem;
        background: transparent;
        border-radius: 0;
        position: relative;
        z-index: 2;
    }
    .aegis-conflict-card h2 {
        font-family: var(--font-head);
        text-transform: uppercase;
        letter-spacing: 0.14em !important;
    }
</style>
