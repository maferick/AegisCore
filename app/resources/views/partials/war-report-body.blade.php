    @php
        $fmtIsk = function (float $v): string {
            if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
            if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
            if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
            return number_format($v, 0);
        };
        $fmtNum = fn ($n) => number_format((int) $n);
        $sevColor = function (?float $sec): string {
            if ($sec === null) return '#9ca3af';
            if ($sec >= 0.5) return '#6dd6ff';
            if ($sec >= 0.0) return '#d49862';
            return '#c474a8';
        };
        // EVE imagery via local proxy (storage/app/eve-images cache).
        // Each helper returns null for missing/zero ids so the blade
        // can `@if ($icon)` to skip rendering. size=64 covers 16-32px
        // display sizes at 2× DPR cleanly; bump per call when needed.
        $shipIcon = fn (?int $id, int $size = 64) => $id ? "/img/type/{$id}?size={$size}" : null;
        $charIcon = fn (?int $id, int $size = 64) => ($id !== null && $id > 0) ? "/img/character/{$id}?size={$size}" : null;
        $allianceIcon = fn (?int $id, int $size = 64) => ($id !== null && $id > 0) ? "/img/alliance/{$id}?size={$size}" : null;
    @endphp
    <style>
        /* ============================================================
           HUD overrides — applied after the legacy palette rules so
           every selector wins on cascade. Goal: capsuleer console look,
           not Grafana. Cyan = Side A + interactive, magenta = Side B,
           gold = scarce achievement accent, platinum = neutral data.
           ============================================================ */

        .km-card,
        .wc-region-panel,
        main.public-main > div[style*="border:1px solid"] {
            font-family: var(--font-body);
        }

        /* Section card chrome. Single rule applied to all section
           containers — the .km-card class AND the inline-styled
           section divs the partial uses for hotspots, leaderboards,
           pilots, alliances, structures, side breakdowns. Approach:
           rectangle + hairline cyan border + 4 corner brackets
           painted via gradient bg layers + a thin gold top strip.
           NO clip-path — clip-path orphans the border at the cut
           edges, leaving visual gaps. */
        :is(
            .km-card,
            main div[style*="border-radius:8px"][style*="padding:0.85rem 1rem"],
            main div[style*="border-radius:8px"][style*="padding:1rem"],
            .wc-region-panel
        ) {
            position: relative;
            background:
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 14px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 1px 14px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 0 / 14px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 0 / 1px 14px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 100% / 14px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 calc(100% - 14px) / 1px 14px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 100% / 14px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% calc(100% - 14px) / 1px 14px no-repeat,
                linear-gradient(180deg, rgba(109,214,255,0.025) 0%, transparent 60%),
                radial-gradient(rgba(109,214,255,0.05) 1px, transparent 1.5px) 0 0 / 22px 22px,
                var(--bg-card) !important;
            border: 1px solid rgba(109,214,255,0.10) !important;
            border-radius: 0 !important;
        }
        :is(
            .km-card,
            main div[style*="border-radius:8px"][style*="padding:0.85rem 1rem"],
            main div[style*="border-radius:8px"][style*="padding:1rem"],
            .wc-region-panel
        )::before {
            /* Gold top accent strip — one rare gold cue per card. */
            content: '';
            position: absolute;
            top: 0; left: 22%; right: 22%;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, var(--hud-gold) 30%, var(--hud-gold) 70%, transparent 100%);
            opacity: 0.75;
            pointer-events: none;
            z-index: 1;
        }
        .km-card h1,
        .km-card h2,
        .km-card h3 {
            font-family: var(--font-head);
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-weight: 700;
            color: var(--hud-platinum);
        }
        .km-card h2 .muted,
        .km-card h3 .muted {
            font-family: var(--font-body);
            font-weight: 500;
            text-transform: none;
            letter-spacing: 0.02em;
            color: var(--hud-platinum-dim);
        }

        /* Numeric values use Share Tech Mono for tactical readout look. */
        .wr-stat-num,
        .km-stat-value,
        .km-attacker-damage,
        .bt-vs-stats,
        .bt-vs-eff,
        .wr-pill-value,
        .wr-vs-stats,
        .wr-vs-eff,
        .wr-balance-legend,
        .wr-war-verdict {
            font-family: var(--font-mono) !important;
            letter-spacing: 0.02em;
        }

        /* HERO BANNER — flat rectangle + corner brackets matching the
           card chrome, no clip-path. */
        #war-hero {
            border-radius: 0 !important;
            border: 1px solid rgba(109,214,255,0.12) !important;
            background:
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 16px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 1px 16px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 0 / 16px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 0 / 1px 16px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 100% / 16px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 calc(100% - 16px) / 1px 16px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 100% / 16px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% calc(100% - 16px) / 1px 16px no-repeat,
                linear-gradient(135deg, rgba(109,214,255,0.06) 0%, transparent 50%, rgba(196,116,168,0.06) 100%),
                radial-gradient(rgba(109,214,255,0.05) 1px, transparent 1.5px) 0 0 / 22px 22px,
                var(--bg-card) !important;
            position: relative;
        }
        #war-hero::before {
            content: '';
            position: absolute; top: 0; left: 18%; right: 18%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--hud-gold), transparent);
            opacity: 0.85;
        }
        #war-hero h1 {
            font-family: var(--font-head) !important;
            text-transform: uppercase !important;
            letter-spacing: 0.18em !important;
            font-weight: 900 !important;
        }
        #war-hero h1 span:first-child { color: var(--hud-cyan) !important; }
        #war-hero h1 span:nth-child(3) { color: var(--hud-magenta) !important; }
        #war-hero h1 span:nth-child(2) {
            color: var(--hud-gold) !important;
            font-weight: 700 !important;
            text-shadow: 0 0 8px rgba(244,199,92,0.45);
        }

        /* Stat pills — flat rectangle + thin gold top edge. No
           clip-path, so the border stays solid all the way around. */
        .wr-pill {
            position: relative;
            border-radius: 0 !important;
            border: 1px solid rgba(109,214,255,0.18) !important;
            background:
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 10px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 1px 10px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 100% / 10px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% calc(100% - 10px) / 1px 10px no-repeat,
                var(--bg-panel) !important;
        }
        .wr-pill::before {
            content: '';
            position: absolute; top: 0; left: 14px; right: 14px;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--hud-gold), transparent);
            opacity: 0.55;
        }
        .wr-pill-label {
            font-family: var(--font-head) !important;
            color: var(--hud-platinum-dim) !important;
            letter-spacing: 0.14em !important;
        }
        .wr-pill-value {
            color: var(--hud-platinum) !important;
            font-family: var(--font-mono) !important;
        }
        /* Hero ISK destroyed pill is allowed to use gold — counts as
           one of the three permitted gold treatments on screen. */
        .wr-pill:nth-child(4) .wr-pill-value { color: var(--hud-gold) !important; }

        /* SIDE CHIPS — cyan & magenta soft glow, corner brackets in
           the side color, no clip-path. */
        .wr-vs-chip {
            position: relative;
            border-radius: 0 !important;
            background:
                linear-gradient(var(--side-color), var(--side-color)) 0 0 / 14px 1px no-repeat,
                linear-gradient(var(--side-color), var(--side-color)) 0 0 / 1px 14px no-repeat,
                linear-gradient(var(--side-color), var(--side-color)) 100% 0 / 14px 1px no-repeat,
                linear-gradient(var(--side-color), var(--side-color)) 100% 0 / 1px 14px no-repeat,
                linear-gradient(var(--side-color), var(--side-color)) 0 100% / 14px 1px no-repeat,
                linear-gradient(var(--side-color), var(--side-color)) 0 calc(100% - 14px) / 1px 14px no-repeat,
                linear-gradient(var(--side-color), var(--side-color)) 100% 100% / 14px 1px no-repeat,
                linear-gradient(var(--side-color), var(--side-color)) 100% calc(100% - 14px) / 1px 14px no-repeat,
                var(--bg-panel) !important;
            border: 1px solid color-mix(in srgb, var(--side-color) 40%, transparent) !important;
            box-shadow:
                0 0 24px color-mix(in srgb, var(--side-color) 18%, transparent),
                inset 0 0 18px color-mix(in srgb, var(--side-color) 6%, transparent);
        }
        .wr-vs-chip[style*="6dd6ff"] { box-shadow: 0 0 24px var(--hud-cyan-soft), inset 0 0 18px rgba(109,214,255,0.05); }
        .wr-vs-chip[style*="c474a8"] { box-shadow: 0 0 24px var(--hud-magenta-soft), inset 0 0 18px rgba(196,116,168,0.05); }
        /* Bracket ticks already painted by gradient layers above; no
           pseudo brackets needed here. */
        .wr-vs-name {
            font-family: var(--font-head) !important;
            text-transform: uppercase !important;
            letter-spacing: 0.10em !important;
            color: var(--hud-platinum) !important;
        }
        .wr-vs-label {
            font-family: var(--font-head) !important;
            color: var(--hud-platinum-dim) !important;
        }
        .wr-vs-stats { color: var(--hud-platinum) !important; }
        .wr-vs-unit { color: var(--hud-platinum-dim) !important; }
        .wr-vs-eff { color: var(--side-color) !important; }
        .wr-vs-sep {
            color: var(--hud-gold) !important;
            text-shadow: 0 0 8px rgba(244,199,92,0.50);
            font-family: var(--font-head) !important;
            font-weight: 900 !important;
        }

        /* WAR-BALANCE BARS — segmented pip strip, no border-radius.
           Inline `background: #hex; width: N%` is set by blade. We
           need to keep the width but replace the solid fill with the
           pip pattern. background-color: transparent kills only the
           solid color, leaving background-image (pip gradient) live. */
        .wr-balance {
            background: rgba(109,214,255,0.05) !important;
            border-radius: 0 !important;
            border: 1px solid rgba(109,214,255,0.10) !important;
            height: 10px !important;
            position: relative;
            overflow: hidden;
        }
        .wr-balance-fill {
            height: 100% !important;
            background-color: transparent !important;
            background-image: repeating-linear-gradient(90deg,
                currentColor 0,
                currentColor 12px,
                transparent 12px,
                transparent 14px) !important;
        }
        .wr-balance-fill[style*="86efac"],
        .wr-balance-fill[style*="6dd6ff"] { color: var(--hud-cyan); }
        .wr-balance-fill[style*="fca5a5"],
        .wr-balance-fill[style*="c474a8"],
        .wr-balance-fill[style*="fdba74"],
        .wr-balance-fill[style*="d49862"] { color: var(--hud-magenta); }
        .wr-balance-legend { color: var(--hud-platinum-dim) !important; }
        .wr-balance-legend span[style*="86efac"],
        .wr-balance-legend span[style*="6dd6ff"] { color: var(--hud-cyan) !important; }
        .wr-balance-legend span[style*="fca5a5"],
        .wr-balance-legend span[style*="c474a8"],
        .wr-balance-legend span[style*="fdba74"] { color: var(--hud-magenta) !important; }

        .wr-war-label {
            font-family: var(--font-head) !important;
            color: var(--hud-platinum-dim) !important;
            letter-spacing: 0.14em !important;
        }
        .wr-war-verdict { color: var(--hud-platinum) !important; }

        /* JUMP NAV — angled chips, gold for active. */
        .wr-jump-nav {
            background: linear-gradient(90deg, rgba(109,214,255,0.10), rgba(8,12,22,0.80) 25%, rgba(8,12,22,0.80) 75%, rgba(109,214,255,0.10)) !important;
            border-top: 1px solid var(--hud-line) !important;
            border-bottom: 1px solid var(--hud-line) !important;
            position: relative;
        }
        .wr-jump-nav::before, .wr-jump-nav::after {
            content: '';
            position: absolute; top: 50%; transform: translateY(-50%);
            width: 8px; height: 10px;
            border: 1px solid var(--hud-gold);
            opacity: 0.8;
        }
        .wr-jump-nav::before { left: 4px; border-right: none; }
        .wr-jump-nav::after { right: 4px; border-left: none; }
        .wr-jump-nav a {
            font-family: var(--font-head) !important;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--hud-cyan) !important;
            background: linear-gradient(180deg, rgba(109,214,255,0.06), rgba(109,214,255,0.02)) !important;
            border: 1px solid rgba(109,214,255,0.30) !important;
            border-radius: 0 !important;
            padding: 0.3rem 0.7rem !important;
            transition: background 0.12s ease, color 0.12s ease, border-color 0.12s ease;
            position: relative;
        }
        .wr-jump-nav a:hover {
            background: linear-gradient(180deg, rgba(109,214,255,0.16), rgba(109,214,255,0.06)) !important;
            border-color: var(--hud-cyan) !important;
            color: #ffffff !important;
            box-shadow: 0 0 10px rgba(109,214,255,0.30);
        }
        .wr-jump-nav a.active {
            color: var(--hud-gold) !important;
            border-color: var(--hud-gold) !important;
            background: linear-gradient(180deg, rgba(244,199,92,0.16), rgba(244,199,92,0.04)) !important;
            box-shadow: 0 0 12px rgba(244,199,92,0.30);
        }

        /* SSO + quote rotator — share the HUD chrome. */
        .wr-sso-btn {
            font-family: var(--font-head) !important;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            background:
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 10px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 1px 10px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 100% / 10px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% calc(100% - 10px) / 1px 10px no-repeat,
                linear-gradient(180deg, rgba(109,214,255,0.18), rgba(109,214,255,0.04)) !important;
            border: 1px solid rgba(109,214,255,0.40) !important;
            border-radius: 0 !important;
            color: var(--hud-platinum) !important;
        }
        .wr-quote-box {
            border: 1px solid rgba(109,214,255,0.18) !important;
            border-radius: 0 !important;
            background:
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 10px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 0 0 / 1px 10px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% 100% / 10px 1px no-repeat,
                linear-gradient(var(--hud-cyan), var(--hud-cyan)) 100% calc(100% - 10px) / 1px 10px no-repeat,
                var(--bg-panel) !important;
        }
        .wr-quote-label {
            font-family: var(--font-head) !important;
            color: var(--hud-platinum-dim) !important;
        }
        .wr-q-current { color: var(--hud-platinum) !important; }

        /* Hotspot system codes — neutral platinum, cyan on hover.
           Replaces the old red-everywhere stylistic tint. */
        .km-stat .km-stat-label > span:first-child + span,
        .km-stat .km-stat-label span[style*="color:#e5e5e7"] {
            color: var(--hud-platinum) !important;
        }
        .km-stat:hover .km-stat-label span[style*="color:#e5e5e7"] {
            color: var(--hud-cyan) !important;
        }

        /* Badge pills (Tip of the Spear etc.) — outlined gold, not
           filled. Single pass: target the existing inline-styled
           gold backgrounds and reduce them to chrome. */
        span[style*="background:rgba(229,169,0"][style*="color:#f4c75c"],
        span[style*="background:rgba(202,138,4"],
        span[style*="background:rgba(250,204,21"] {
            background: transparent !important;
            border: 1px solid var(--hud-gold) !important;
            color: var(--hud-gold) !important;
            font-family: var(--font-body) !important;
            font-weight: 700 !important;
            letter-spacing: 0.10em !important;
            text-shadow: 0 0 6px rgba(244,199,92,0.35);
            transition: background 0.12s ease;
        }
        span[style*="background:rgba(229,169,0"]:hover,
        span[style*="background:rgba(202,138,4"]:hover,
        span[style*="background:rgba(250,204,21"]:hover {
            background: var(--hud-gold-soft) !important;
            color: #ffffff !important;
        }

        /* "VS" separator on hero name — gold glow. */
        #war-hero h1 .wr-vs-text { color: var(--hud-gold) !important; text-shadow: 0 0 10px rgba(244,199,92,0.50); }

        .aegis-icon {
            display:inline-block; vertical-align:middle;
            border-radius:2px;
            background: rgba(255,255,255,0.04);
        }
        .aegis-icon-ship    { width:16px; height:16px; }
        .aegis-icon-ship-md { width:24px; height:24px; }
        .aegis-icon-char    { width:16px; height:16px; border-radius:50%; }
        .aegis-icon-char-md { width:28px; height:28px; border-radius:50%; }
        .aegis-icon-ally    { width:14px; height:14px; }
        .aegis-icon-ally-md { width:22px; height:22px; }
    </style>
    @php
        $tiles = [
            'wc' => ['label' => 'WinterCo losses', 'tint' => '#6dd6ff', 'count' => $totals['wc']['kms'], 'isk' => $totals['wc']['isk']],
            'op' => ['label' => $opposing_label . ' losses', 'tint' => $opposing_tint, 'count' => $totals['op']['kms'], 'isk' => $totals['op']['isk']],
        ];
        $sideKeys = ['wc', 'op'];
        $totalKms = $totals['wc']['kms'] + $totals['op']['kms'];
        $totalIsk = $totals['wc']['isk'] + $totals['op']['isk'];
    @endphp

    {{-- Hero banner --}}
    @php
        $label = $display_label ?? ('WinterCo vs ' . $opposing_label);
        [$leftRaw, $rightRaw] = array_pad(array_map('trim', explode(' vs ', $label, 2)), 2, '');
        $colorOf = fn (string $name): string => $name === 'WinterCo' ? '#6dd6ff' : $opposing_tint;

        // Three "war" verdicts: ISK, Structures, Systems. Each has
        // - text: one-line plain-English verdict
        // - color: who's leading (green=WC, opposing tint=op, gray=tie)
        // - wc_share / op_share: percentages for the balance bar
        // - wc_legend / op_legend: small text under the bar
        $_wcLost = (float) $totals['wc']['isk'];
        $_opLost = (float) $totals['op']['isk'];

        $verdictFor = function (float $wcVal, float $opVal, string $label, string $unitFmt, string $wcLegLabel, string $opLegLabel) use ($opposing_label, $opposing_tint): array {
            $diff = $wcVal - $opVal;
            $tot = max(1e-9, $wcVal + $opVal);
            $wcShare = ($wcVal / $tot) * 100;
            $opShare = ($opVal / $tot) * 100;
            $thresh = $unitFmt === 'isk' ? 1e9 : 1; // tie band
            if (abs($diff) < $thresh) {
                $text = "Roughly even — both sides about level on {$label}.";
                $color = '#cbd5e1';
            } elseif ($diff > 0) {
                $text = 'WinterCo: ' . $wcLegLabel;
                $color = '#6dd6ff';
            } else {
                $text = $opposing_label . ': ' . $opLegLabel;
                $color = $opposing_tint;
            }
            return [
                'text' => $text, 'color' => $color,
                'wc_share' => $wcShare, 'op_share' => $opShare,
                'wc_legend' => $wcLegLabel, 'op_legend' => $opLegLabel,
            ];
        };

        // ISK war: each side's "score" = ISK they DESTROYED. WC
        // destroyed = op's losses, and vice versa.
        $_iskWcScore = $_opLost;            // ISK WC destroyed
        $_iskOpScore = $_wcLost;            // ISK Op destroyed
        $_iskDiff = $_iskWcScore - $_iskOpScore;
        $_iskWar = $verdictFor(
            $_iskWcScore, $_iskOpScore, 'ISK',
            'isk',
            'destroyed ' . $fmtIsk($_iskWcScore) . ', lost ' . $fmtIsk($_wcLost) . ($_iskDiff > 0 ? ' (net +' . $fmtIsk(abs($_iskDiff)) . ')' : ''),
            'destroyed ' . $fmtIsk($_iskOpScore) . ', lost ' . $fmtIsk($_opLost) . ($_iskDiff < 0 ? ' (net +' . $fmtIsk(abs($_iskDiff)) . ')' : ''),
        );
        $_verdict = $_iskWar;  // back-compat: existing chip still references $_verdict
        $_wcShare = $_iskWar['wc_share'];
        $_opShare = $_iskWar['op_share'];

        // Structure war.
        $_strWc = $structure_war['wc'] ?? ['lost' => 0, 'isk_lost' => 0.0];
        $_strOp = $structure_war['op'] ?? ['lost' => 0, 'isk_lost' => 0.0];
        $_strWcScore = (float) $_strOp['isk_lost'];      // value WC destroyed
        $_strOpScore = (float) $_strWc['isk_lost'];      // value Op destroyed
        $_strWcCount = (int) $_strOp['lost'];            // # WC destroyed
        $_strOpCount = (int) $_strWc['lost'];            // # Op destroyed
        $_strWar = $verdictFor(
            $_strWcScore, $_strOpScore, 'structures',
            'isk',
            $_strWcCount . ' killed (' . $fmtIsk($_strWcScore) . '), lost ' . $_strWc['lost'] . ' (' . $fmtIsk((float) $_strWc['isk_lost']) . ')',
            $_strOpCount . ' killed (' . $fmtIsk($_strOpScore) . '), lost ' . $_strOp['lost'] . ' (' . $fmtIsk((float) $_strOp['isk_lost']) . ')',
        );

        // Combat zones: per system, whichever side landed more kills
        // wins that system. Use plain wording — drop the "leads on
        // {label} —" prefix that verdictFor adds, since the label
        // already says "Combat zones".
        $_sysWc = $systems_war['wc'] ?? ['dominated' => 0, 'contested' => 0, 'total' => 0];
        $_sysOp = $systems_war['op'] ?? ['dominated' => 0, 'contested' => 0, 'total' => 0];
        $_sysWcDom = (int) $_sysWc['dominated'];
        $_sysOpDom = (int) $_sysOp['dominated'];
        $_sysContested = (int) $_sysWc['contested'];
        $_sysTotal = (int) $_sysWc['total'];
        $_sysShareWc = $_sysTotal > 0 ? ($_sysWcDom / $_sysTotal) * 100 : 0;
        $_sysShareOp = $_sysTotal > 0 ? ($_sysOpDom / $_sysTotal) * 100 : 0;
        if ($_sysWcDom > $_sysOpDom) {
            $_sysVerdict = ['text' => 'WinterCo controlled the kill count in ' . number_format($_sysWcDom) . ' of ' . number_format($_sysTotal) . ' contested systems', 'color' => '#6dd6ff'];
        } elseif ($_sysOpDom > $_sysWcDom) {
            $_sysVerdict = ['text' => $opposing_label . ' controlled the kill count in ' . number_format($_sysOpDom) . ' of ' . number_format($_sysTotal) . ' contested systems', 'color' => $opposing_tint];
        } else {
            $_sysVerdict = ['text' => 'Roughly even across ' . number_format($_sysTotal) . ' contested systems', 'color' => '#cbd5e1'];
        }
        $_sysWar = [
            'text' => $_sysVerdict['text'],
            'color' => $_sysVerdict['color'],
            'wc_share' => $_sysShareWc,
            'op_share' => $_sysShareOp,
            'wc_legend' => 'won the kill count in ' . number_format($_sysWcDom) . ' systems',
            'op_legend' => 'won the kill count in ' . number_format($_sysOpDom) . ' systems',
        ];

        // Sov war: prefer real flips diffed from
        // system_sovereignty_history. Falls back to Sov Hub kills, then
        // current snapshot, when no diff is possible (e.g. baseline
        // older than war start hasn't been captured yet).
        $_sovWc = $sov_war['wc'] ?? ['hubs_killed' => 0, 'hubs_lost' => 0, 'sov_now' => 0, 'flips_gained' => 0, 'flips_lost' => 0, 'baseline_date' => null];
        $_sovOp = $sov_war['op'] ?? ['hubs_killed' => 0, 'hubs_lost' => 0, 'sov_now' => 0, 'flips_gained' => 0, 'flips_lost' => 0, 'baseline_date' => null];
        $_sovWcGain = (int) $_sovWc['flips_gained'];
        $_sovOpGain = (int) $_sovOp['flips_gained'];
        $_sovWcLost = (int) $_sovWc['flips_lost'];
        $_sovOpLost = (int) $_sovOp['flips_lost'];
        $_sovWcWreck = (int) $_sovWc['hubs_killed'];
        $_sovOpWreck = (int) $_sovOp['hubs_killed'];
        $_sovWcNow = (int) $_sovWc['sov_now'];
        $_sovOpNow = (int) $_sovOp['sov_now'];
        $_sovBaseline = $_sovWc['baseline_date'] ?? null;

        if (($_sovWcGain + $_sovOpGain) > 0) {
            // Real flips since baseline.
            $_sovWar = $verdictFor(
                (float) $_sovWcGain, (float) $_sovOpGain, 'sov flips',
                'count',
                $_sovWcGain . ' systems gained · ' . $_sovWcLost . ' lost (since ' . $_sovBaseline . ')',
                $_sovOpGain . ' systems gained · ' . $_sovOpLost . ' lost (since ' . $_sovBaseline . ')',
            );
        } elseif ($_sovWcWreck > 0 || $_sovOpWreck > 0) {
            $_sovWar = $verdictFor(
                (float) $_sovWcWreck, (float) $_sovOpWreck, 'sov flips',
                'count',
                $_sovWcWreck . ' enemy Sov Hubs broken · holds ' . $_sovWcNow . ' sov systems',
                $_sovOpWreck . ' enemy Sov Hubs broken · holds ' . $_sovOpNow . ' sov systems',
            );
        } else {
            $_sovWar = $verdictFor(
                (float) $_sovWcNow, (float) $_sovOpNow, 'current sov',
                'count',
                $_sovWcNow . ' sov systems held' . ($_sovBaseline ? ' · tracking flips since ' . $_sovBaseline : ' (flip tracking starts today)'),
                $_sovOpNow . ' sov systems held' . ($_sovBaseline ? ' · tracking flips since ' . $_sovBaseline : ' (flip tracking starts today)'),
            );
        }

        // Side stats blocks — mirror the battle report's bt-vs-chip
        // layout. WC and opposing each get pilots / kills / ISK lost
        // / efficiency. Kills for WC = opposing's losses count and
        // vice versa. Efficiency is destroyed-isk / total-traded-isk.
        $wcLost = (float) $totals['wc']['isk'];
        $opLost = (float) $totals['op']['isk'];
        $wcEff = ($wcLost + $opLost) > 0 ? round(($opLost / ($wcLost + $opLost)) * 100, 1) : null;
        $opEff = ($wcLost + $opLost) > 0 ? round(($wcLost / ($wcLost + $opLost)) * 100, 1) : null;

        $wcLeadId = $totals['wc']['lead_alliance_id'] ?? null;
        $opLeadId = $totals['op']['lead_alliance_id'] ?? null;
        $wcHeadline = $totals['wc']['lead_alliance_name'] ?? 'WinterCo';
        $opHeadline = $totals['op']['lead_alliance_name'] ?? $opposing_label;

        // Daily efficiency series for the sparkline next to "X% eff".
        // Side eff[D] = isk_destroyed[D] / total_traded_isk[D] × 100.
        // Days with no traded ISK get null (gap in the line).
        $wcDaily = $rollups['wc']['daily'] ?? [];
        $opDaily = $rollups['op']['daily'] ?? [];
        $iskByDay = [];
        foreach ($wcDaily as $r) $iskByDay[(string) $r->day]['wc'] = (float) $r->isk;
        foreach ($opDaily as $r) $iskByDay[(string) $r->day]['op'] = (float) $r->isk;
        ksort($iskByDay);
        $wcEffSeries = [];
        $opEffSeries = [];
        foreach ($iskByDay as $day => $v) {
            $w = $v['wc'] ?? 0.0;
            $o = $v['op'] ?? 0.0;
            $tot = $w + $o;
            if ($tot <= 0) {
                $wcEffSeries[] = null;
                $opEffSeries[] = null;
                continue;
            }
            $wcEffSeries[] = ($o / $tot) * 100.0;
            $opEffSeries[] = ($w / $tot) * 100.0;
        }

        // Stat tuples for the chip render (label, alliance, color,
        // pilots, kills, isk_lost, eff, lead_alliance_id).
        $sideChips = [
            [
                'label' => 'Side A',
                'headline' => $wcHeadline,
                'sub' => 'WinterCo bloc',
                'color' => '#6dd6ff',
                'pilots' => (int) $totals['wc']['pilots'],
                'kills' => (int) $totals['op']['kms'],
                'isk_lost' => $wcLost,
                'eff' => $wcEff,
                'eff_series' => $wcEffSeries,
                'logo_aid' => $wcLeadId,
            ],
            [
                'label' => 'Side B',
                'headline' => $opHeadline,
                'sub' => $opposing_label . ' bloc',
                'color' => $opposing_tint,
                'pilots' => (int) $totals['op']['pilots'],
                'kills' => (int) $totals['wc']['kms'],
                'isk_lost' => $opLost,
                'eff' => $opEff,
                'eff_series' => $opEffSeries,
                'logo_aid' => $opLeadId,
            ],
        ];
    @endphp
    <div id="war-hero" data-tab="overview" style="position:relative; padding:1.4rem 1.6rem 1.1rem; margin-bottom:1rem; border-radius:10px;
                background:linear-gradient(135deg, rgba(34,197,94,0.08) 0%, rgba(0,0,0,0.4) 50%, rgba(239,68,68,0.10) 100%);
                border:1px solid rgba(255,255,255,0.10); overflow:hidden;">
        <div style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.12em; margin-bottom:0.35rem;">Active conflict</div>
        <h1 style="margin:0 0 0.4rem 0; font-size:1.4rem; color:#e5e5e7; font-weight:700; letter-spacing:0.02em;">
            <span style="color:{{ $colorOf($leftRaw) }};">{{ $leftRaw }}</span>
            <span style="color:#7a7a82; font-weight:400;"> vs </span>
            <span style="color:{{ $colorOf($rightRaw) }};">{{ $rightRaw }}</span>
        </h1>

        {{-- Three war-balance bars (ISK, Structures, Systems). Each
             gets a one-line verdict + split-fill bar + legend. --}}
        @php
            $_wars = [
                ['label' => 'ISK war',        'data' => $_iskWar],
                ['label' => 'Structure war',  'data' => $_strWar],
                ['label' => 'Combat zones',   'data' => $_sysWar],
                ['label' => 'Sov war',        'data' => $_sovWar],
            ];
        @endphp
        <div class="wr-wars">
            @foreach ($_wars as $war)
                <div class="wr-war-block">
                    <div class="wr-war-label">{{ $war['label'] }}</div>
                    <div class="wr-war-verdict" style="color:{{ $war['data']['color'] }};">
                        {{ $war['data']['text'] }}
                    </div>
                    <div class="wr-balance">
                        <span class="wr-balance-fill" style="background:#6dd6ff; width:{{ $war['data']['wc_share'] }}%;"></span>
                        <span class="wr-balance-fill" style="background:{{ $opposing_tint }}; width:{{ $war['data']['op_share'] }}%;"></span>
                    </div>
                    <div class="wr-balance-legend">
                        <span style="color:#6dd6ff;">WinterCo · {{ $war['data']['wc_legend'] }}</span>
                        <span style="color:{{ $opposing_tint }};">{{ $opposing_label }} · {{ $war['data']['op_legend'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Stat pills (replaces the interpunct sentence). Each value
             gets its own labelled tile so the eye finds the number it
             wants without reading prose. --}}
        <div class="wr-hero-pills">
            <div class="wr-pill"><div class="wr-pill-label">Started</div><div class="wr-pill-value">{{ \Carbon\Carbon::parse($war_start)->format('Y-m-d') }}</div></div>
            <div class="wr-pill"><div class="wr-pill-label">Day</div><div class="wr-pill-value">{{ $fmtNum($total_days) }}</div></div>
            <div class="wr-pill"><div class="wr-pill-label">Killmails</div><div class="wr-pill-value">{{ $fmtNum($totalKms) }}</div></div>
            <div class="wr-pill"><div class="wr-pill-label">ISK destroyed</div><div class="wr-pill-value" style="color:#f4c75c;">{{ $fmtIsk($totalIsk) }}</div></div>
        </div>

        {{-- Side stats chips: pilots / kills / ISK lost / efficiency
             per side, mirrors the battle report's vs header. --}}
        <div class="wr-vs-chipbar">
            @foreach ($sideChips as $idx => $s)
                @if ($idx > 0)
                    <div class="wr-vs-sep" aria-hidden="true">vs</div>
                @endif
                <div class="wr-vs-chip" style="--side-color: {{ $s['color'] }};">
                    @if ($s['logo_aid'])
                        <img src="https://images.evetech.net/alliances/{{ $s['logo_aid'] }}/logo?size=64"
                             referrerpolicy="no-referrer" decoding="async"
                             width="64" height="64" class="wr-vs-logo" alt="">
                    @else
                        <div class="wr-vs-logo placeholder">{{ $s['label'][5] ?? '?' }}</div>
                    @endif
                    <div class="wr-vs-info">
                        <div class="wr-vs-label">{{ $s['label'] }}</div>
                        <div class="wr-vs-name" title="{{ $s['headline'] }}">{{ $s['headline'] }}</div>
                        <div class="wr-vs-sub">{{ $s['sub'] }}</div>
                        <div class="wr-vs-stats">
                            {{ $fmtNum($s['pilots']) }} <span class="wr-vs-unit">pilots</span> ·
                            {{ $fmtNum($s['kills']) }} <span class="wr-vs-unit">kills</span> ·
                            {{ $fmtIsk($s['isk_lost']) }} <span class="wr-vs-unit">ISK lost</span>
                        </div>
                        <div class="wr-vs-eff-row">
                            <span class="wr-vs-eff" style="color: {{ $s['color'] }};">
                                {{ $s['eff'] !== null ? $s['eff'].'% eff' : '—' }}
                            </span>
                            @php
                                $_series = array_values(array_filter($s['eff_series'], fn ($v) => $v !== null));
                                $_pts = [];
                                if (count($_series) >= 2) {
                                    $w = 90; $h = 18; $pad = 1;
                                    $n = count($_series);
                                    foreach ($_series as $i => $v) {
                                        $px = round($pad + ($i / max(1, $n - 1)) * ($w - 2 * $pad), 1);
                                        // y inverts: 0% at bottom, 100% at top
                                        $py = round($pad + (1 - ($v / 100)) * ($h - 2 * $pad), 1);
                                        $_pts[] = "{$px},{$py}";
                                    }
                                }
                            @endphp
                            @if ($_pts !== [])
                                <svg class="wr-vs-spark" viewBox="0 0 90 18" preserveAspectRatio="none"
                                     aria-label="Daily efficiency over the conflict"
                                     title="Daily efficiency over the conflict">
                                    {{-- 50% reference line --}}
                                    <line x1="1" y1="9" x2="89" y2="9" stroke="rgba(255,255,255,0.10)" stroke-width="0.5" stroke-dasharray="2 2"/>
                                    <polyline fill="none" stroke="{{ $s['color'] }}" stroke-width="1.4"
                                              stroke-linecap="round" stroke-linejoin="round"
                                              points="{{ implode(' ', $_pts) }}"/>
                                    @php
                                        // Last point dot for the "current" indicator.
                                        [$lpx, $lpy] = explode(',', end($_pts));
                                    @endphp
                                    <circle cx="{{ $lpx }}" cy="{{ $lpy }}" r="1.5" fill="{{ $s['color'] }}"/>
                                </svg>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="wr-hero-cta-row">
            @if (! empty($conflict_key))
                <a href="/auth/eve/war-stats?conflict={{ $conflict_key }}" class="wr-sso-btn">
                    <span>🔓</span><span>See your effort — sign in via EVE</span>
                </a>
            @endif
            <div class="wr-quote-box" aria-label="Things heard during this war">
                <div class="wr-quote-label">📡 Things heard during this war</div>
                <div class="wr-quote-stage" id="wr-quote-stage">
                    {{-- Filled by /js/war-quotes.js (150-quote rotator,
                         5-min interval, time-bucketed so all visitors
                         see the same quote at the same moment). --}}
                    <span class="wr-q-current">…</span>
                </div>
            </div>
        </div>
        <script src="/js/war-quotes.js" defer></script>
    </div>
    <style>
        .wr-vs-chipbar {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 1rem;
            align-items: stretch;
        }
        @media (max-width: 720px) {
            .wr-vs-chipbar { grid-template-columns: 1fr; }
            .wr-vs-sep { padding: 0.2rem 0; }
        }
        .wr-vs-chip {
            display: flex; gap: 0.85rem; align-items: center;
            padding: 0.7rem 0.9rem;
            border: 1px solid var(--side-color, rgba(255,255,255,0.18));
            border-radius: 8px;
            background: rgba(0,0,0,0.35);
        }
        .wr-vs-sep {
            display: flex; align-items: center; justify-content: center;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.78rem; font-weight: 700; letter-spacing: 0.18em;
            color: #3a3a42;
        }
        .wr-vs-logo { width: 56px; height: 56px; border-radius: 6px; flex-shrink: 0;
            border: 2px solid var(--side-color, rgba(255,255,255,0.20)); background: rgba(0,0,0,0.30); }
        .wr-vs-logo.placeholder { display:flex; align-items:center; justify-content:center;
            font-weight:900; font-size:1.6rem; color: var(--side-color, #e5e5e7); }
        .wr-vs-info { min-width: 0; flex: 1; }
        .wr-vs-info .wr-vs-label { font-size: 0.58rem; color: #7a7a82; text-transform: uppercase; letter-spacing: 0.12em; font-family: 'JetBrains Mono', monospace; }
        .wr-vs-info .wr-vs-name {
            font-size: 1rem; font-weight: 700; color: #e5e5e7; line-height: 1.2;
            margin-top: 0.05rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .wr-vs-info .wr-vs-sub { font-size: 0.66rem; color: #7a7a82; }
        .wr-vs-info .wr-vs-stats { font-family: 'JetBrains Mono', monospace; font-size: 0.7rem; color: #cbd5e1; margin-top: 0.25rem; font-variant-numeric: tabular-nums; line-height: 1.5; }
        .wr-vs-info .wr-vs-unit { color: #7a7a82; font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 500; margin-left: 1px; }
        .wr-vs-info .wr-vs-eff-row { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.1rem; }
        .wr-vs-info .wr-vs-eff { font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: 1.05rem; font-variant-numeric: tabular-nums; }
        .wr-vs-info .wr-vs-spark { width: 90px; height: 18px; flex-shrink: 0; opacity: 0.85; }

        /* Three war balance blocks (ISK / Structures / Systems) */
        .wr-wars {
            display: flex; flex-direction: column;
            gap: 0.7rem;
            margin-bottom: 1rem;
        }
        .wr-war-block { }
        .wr-war-label {
            font-size: 0.55rem; color: #7a7a82; text-transform: uppercase; letter-spacing: 0.10em;
            font-family: 'JetBrains Mono', monospace;
            margin-bottom: 0.15rem;
        }
        .wr-war-verdict {
            font-size: 0.82rem; font-weight: 600; margin-bottom: 0.35rem;
            font-variant-numeric: tabular-nums;
        }

        /* Verdict + balance bar */
        .wr-balance {
            display: flex; height: 8px; border-radius: 4px; overflow: hidden;
            background: rgba(255,255,255,0.05);
            margin-bottom: 0.3rem;
        }
        .wr-balance-fill { display: block; height: 100%; transition: width 0.3s ease; }
        .wr-balance-legend {
            display: flex; justify-content: space-between;
            font-family: 'JetBrains Mono', monospace; font-size: 0.62rem;
            font-variant-numeric: tabular-nums;
            margin-bottom: 0.85rem;
        }

        /* Stat pills under hero */
        .wr-hero-pills {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        @media (max-width: 720px) {
            .wr-hero-pills { grid-template-columns: repeat(2, 1fr); }
        }
        .wr-pill {
            padding: 0.45rem 0.6rem;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 6px;
            background: rgba(255,255,255,0.02);
        }
        .wr-pill-label { font-size: 0.55rem; color: #7a7a82; text-transform: uppercase; letter-spacing: 0.08em; }
        .wr-pill-value { font-size: 1.05rem; color: #e5e5e7; font-weight: 600; font-family: 'JetBrains Mono', monospace; font-variant-numeric: tabular-nums; margin-top: 0.1rem; }

        /* SSO + quotes row. Each quote shows for ~5 min via CSS-only
           animation. 150 quotes × 5min = 12.5h cycle. No JS (public
           CSP forbids it). step-end keyframes flip opacity discretely. */
        .wr-hero-cta-row {
            display: grid;
            grid-template-columns: minmax(0, auto) minmax(0, 1fr);
            gap: 0.6rem;
            margin-top: 1rem;
            align-items: stretch;
        }
        @media (max-width: 720px) {
            .wr-hero-cta-row { grid-template-columns: 1fr; }
        }
        .wr-sso-btn {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.5rem 0.85rem;
            border: 1px solid rgba(79,208,208,0.40);
            border-radius: 6px;
            background: linear-gradient(135deg, rgba(79,208,208,0.15) 0%, rgba(0,0,0,0.5) 100%);
            color: #e5e5e7; text-decoration: none;
            font-size: 0.7rem; font-weight: 600; letter-spacing: 0.04em;
            white-space: nowrap;
        }
        .wr-quote-box {
            position: relative;
            padding: 0.45rem 0.85rem;
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 6px;
            background: rgba(255,255,255,0.02);
            min-height: 2.6rem;
            display: flex; flex-direction: column; justify-content: center;
            overflow: hidden;
        }
        .wr-quote-label {
            font-size: 0.55rem; color: #7a7a82; text-transform: uppercase; letter-spacing: 0.10em;
            font-family: 'JetBrains Mono', monospace;
            margin-bottom: 0.2rem;
        }
        .wr-quote-stage { position: relative; min-height: 1.1rem; font-size: 0.82rem; color: #cbd5e1; font-style: italic; line-height: 1.3; }
        .wr-q-current { transition: opacity 0.4s ease; }
        .wr-q-current.fading { opacity: 0; }

        /* Sticky jump-nav between sections */
        .wr-jump-nav {
            position: sticky; top: 0; z-index: 50;
            background: rgba(8,8,10,0.92);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 0.4rem 0.5rem;
            margin: 0 -0.25rem 1rem;
            display: flex; gap: 0.35rem; flex-wrap: wrap;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.65rem;
        }
        .wr-jump-nav a {
            padding: 0.25rem 0.6rem;
            border-radius: 4px;
            border: 1px solid rgba(255,255,255,0.06);
            color: #cbd5e1; text-decoration: none;
            transition: background 0.1s ease, color 0.1s ease;
        }
        .wr-jump-nav a:hover { background: rgba(79,208,208,0.15); color: #fff; border-color: rgba(79,208,208,0.30); }
        .wr-section-anchor {
            display: block; position: relative; top: -50px;
            visibility: hidden; height: 0;
        }
        /* Tabular numbers everywhere this partial lives */
        .wr-stat-num { font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', monospace; }
    </style>

    {{-- Tabbed nav. JS swaps body[data-active-tab="X"] on click /
         URL hash; CSS in hud-elevated.css hides every [data-tab]
         except the matching pane. Default = overview. --}}
    <script src="/js/war-tabs.js" defer></script>
    <nav class="wr-jump-nav" aria-label="Section tabs">
        <a href="#tab-overview"        data-tab-link="overview">Overview</a>
        <a href="#tab-hotspots"        data-tab-link="hotspots">Hotspots</a>
        <a href="#tab-leaderboards"    data-tab-link="leaderboards">Leaderboards</a>
        <a href="#tab-top-kills"       data-tab-link="top-kills">Top kills</a>
        <a href="#tab-pilots"          data-tab-link="pilots">Pilots</a>
        <a href="#tab-alliances"       data-tab-link="alliances">Alliances</a>
        <a href="#tab-implants"        data-tab-link="implants">Implants</a>
        <a href="#tab-structures"      data-tab-link="structures">Structures</a>
        <a href="#tab-side-breakdowns" data-tab-link="side-breakdowns">Side breakdowns</a>
    </nav>

    {{-- Live-battle banner — battles with a killmail in the last 90
         min OR end_time still NULL. Click to open the battle report
         (system map + side breakdown + live kill feed). --}}
    @php $live = $live_battles ?? []; @endphp
    @php $olderBattlesUrl = isset($conflict_key) ? '/battles/' . $conflict_key : '/battles'; @endphp
    @if (count($live) > 0)
        <div style="margin-bottom:0.75rem; padding:0.55rem 0.85rem; border:1px solid rgba(109,214,255,0.35); border-radius:8px; background:linear-gradient(90deg, rgba(109,214,255,0.10) 0%, rgba(0,0,0,0.45) 100%);">
            <div style="display:flex; align-items:center; gap:0.6rem; margin-bottom:0.35rem;">
                <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#6dd6ff; box-shadow:0 0 8px #6dd6ff; animation:aegis-live-pulse 1.4s ease-in-out infinite;"></span>
                <strong style="font-size:0.7rem; color:#6dd6ff; letter-spacing:0.08em; text-transform:uppercase;">Live now</strong>
                <span style="font-size:0.6rem; color:#7a7a82;">· {{ count($live) }} battle{{ count($live) === 1 ? '' : 's' }} active · click to open report</span>
                <a href="{{ $olderBattlesUrl }}" style="margin-left:auto; font-size:0.6rem; color:#cbd5e1; text-decoration:none; padding:0.15rem 0.5rem; border:1px solid rgba(255,255,255,0.10); border-radius:4px; background:rgba(255,255,255,0.04);">Older battles →</a>
            </div>
            <div style="display:flex; gap:0.4rem; flex-wrap:wrap;">
                @foreach ($live as $b)
                    @php
                        $secColor = $sevColor((float) ($b->security_status ?? null));
                        $slugOrId = $b->public_slug ?: (string) $b->id;
                    @endphp
                    <a href="/battles/{{ $slugOrId }}" style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.3rem 0.6rem; border:1px solid rgba(109,214,255,0.30); border-radius:5px; background:rgba(0,0,0,0.30); text-decoration:none;">
                        <span style="font-size:0.75rem; font-weight:700; color:{{ $secColor }};">{{ $b->system_name }}</span>
                        <span style="font-size:0.6rem; color:#cbd5e1;">{{ $fmtNum($b->total_kills ?: 0) }} kms</span>
                        <span style="font-size:0.6rem; color:#f4c75c;">{{ $fmtIsk((float) ($b->total_isk_lost ?: 0)) }}</span>
                        <span style="font-size:0.55rem; color:#7a7a82;">last {{ \Carbon\Carbon::parse($b->newest_km)->diffForHumans() }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @else
        <div style="margin-bottom:0.75rem; padding:0.45rem 0.85rem; border:1px solid rgba(255,255,255,0.06); border-radius:8px; background:rgba(0,0,0,0.20); display:flex; align-items:center; gap:0.6rem;">
            <span style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em;">No live battles right now</span>
            <a href="{{ $olderBattlesUrl }}" style="margin-left:auto; font-size:0.6rem; color:#cbd5e1; text-decoration:none; padding:0.15rem 0.5rem; border:1px solid rgba(255,255,255,0.10); border-radius:4px; background:rgba(255,255,255,0.04);">Older battles →</a>
        </div>
    @endif
    <style>
        @keyframes aegis-live-pulse {
            0%   { opacity: 1;   transform: scale(1); }
            50%  { opacity: 0.4; transform: scale(0.85); }
            100% { opacity: 1;   transform: scale(1); }
        }
    </style>

    {{-- Hot-kills ticker is rendered at the bottom of the file as a
         viewport-fixed bar; see the .aegis-ticker-fixed block below.
         A spacer leaves room for the bar so the last section isn't
         hidden behind it. --}}


    {{-- System hotspots --}}
    @if (count($hotspots) > 0)
        <div data-tab="hotspots" style="margin-bottom:1rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(255,255,255,0.02);">
            <div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.5rem;">
                <h2 id="sec-hotspots" style="margin:0; font-size:0.85rem; color:#e5e5e7; scroll-margin-top: 60px;">System hotspots</h2>
                <span style="font-size:0.6rem; color:#7a7a82;">top systems by war-attributable km · entire conflict</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.4rem;">
                @foreach ($hotspots as $h)
                    <div style="padding:0.45rem 0.65rem; border:1px solid rgba(255,255,255,0.06); border-radius:5px; background:rgba(0,0,0,0.20);">
                        <div style="font-size:0.78rem; font-weight:600; color:{{ $sevColor($h->security_status ?? null) }}; letter-spacing:0.02em;">{{ $h->system_name }}</div>
                        <div style="font-size:0.62rem; color:#9ca3af; margin-top:0.15rem;">
                            <strong style="color:#e5e5e7;">{{ $fmtNum($h->km_count) }}</strong> km ·
                            <strong style="color:#f4c75c;">{{ $fmtIsk((float) $h->isk_destroyed) }}</strong>
                        </div>
                        <div style="font-size:0.55rem; color:#7a7a82; margin-top:0.1rem;">last {{ \Carbon\Carbon::parse($h->last_km)->diffForHumans() }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Leaderboards: most-valuable single kills + pilot/alliance rankings --}}
    @php $lb = $leaderboards ?? []; @endphp
    {{-- Per-badge podium — top 3 characters per metric, with reddit-
         meme rank titles. Links to internal /kills detail not needed
         here; portrait + name + alliance + metric value. --}}
    @php
        $podiumTitles = \App\Filament\Portal\Pages\WarReport::PODIUM_TITLES;
        $metricLabels = [
            'kills' => 'Kills you were on',
            'final_blows' => 'Final blows landed',
            'isk_destroyed' => 'ISK destroyed (final blow)',
            'battles_attended' => 'Battles attended',
            'small_gang_kills' => 'Small-gang kills',
            'most_feared' => 'Most feared (high-value hunting)',
            'hardest_to_kill' => 'Hardest to kill (% survival)',
            'biggest_menace' => 'Biggest menace (unique enemies)',
        ];
        $rankColor = [1 => '#f4c75c', 2 => '#cbd5e1', 3 => '#d49862'];
        $rankBg = [
            1 => 'linear-gradient(135deg, rgba(253,224,71,0.18), rgba(0,0,0,0.40))',
            2 => 'linear-gradient(135deg, rgba(203,213,225,0.14), rgba(0,0,0,0.40))',
            3 => 'linear-gradient(135deg, rgba(253,186,116,0.16), rgba(0,0,0,0.40))',
        ];
    @endphp
    @if (! empty($podiums ?? []))
        <div data-tab="leaderboards" style="margin-bottom:1rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(255,255,255,0.02);">
            <div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.6rem; flex-wrap:wrap;">
                <h2 id="sec-leaderboards" style="margin:0; font-size:0.85rem; color:#e5e5e7; scroll-margin-top: 60px;">Top of every leaderboard</h2>
                <span style="font-size:0.6rem; color:#7a7a82;">first three by each badge metric</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:0.6rem;">
                @foreach ($podiums as $metric => $rows)
                    @if (count($rows) === 0) @continue @endif
                    <div style="padding:0.6rem 0.75rem; border:1px solid rgba(255,255,255,0.06); border-radius:6px; background:rgba(0,0,0,0.20);">
                        <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.4rem;">{{ $metricLabels[$metric] ?? $metric }}</div>
                        @foreach ($rows as $i => $r)
                            @php
                                $rank = $i + 1;
                                $title = $podiumTitles[$metric][$rank] ?? '';
                                $color = $rankColor[$rank] ?? '#9ca3af';
                                $bg = $rankBg[$rank] ?? 'rgba(0,0,0,0.20)';
                                $valFmt = match ($metric) {
                                    'isk_destroyed', 'most_feared' => $fmtIsk((float) $r->metric),
                                    'hardest_to_kill' => number_format((float) $r->metric, 1) . '%',
                                    default => $fmtNum((int) $r->metric),
                                };
                            @endphp
                            <div style="display:flex; align-items:center; gap:0.5rem; padding:0.3rem 0; border-bottom:1px solid rgba(255,255,255,0.04);">
                                <div style="flex:0 0 28px; text-align:center;">
                                    <div style="font-size:1rem; font-weight:700; color:{{ $color }};">#{{ $rank }}</div>
                                </div>
                                <img src="/img/character/{{ $r->id }}?size=64" loading="lazy" referrerpolicy="no-referrer" alt=""
                                     class="aegis-icon aegis-icon-char-md" style="flex:0 0 28px;">
                                <div style="flex:1; min-width:0;">
                                    <div style="font-size:0.7rem; color:#e5e5e7; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $r->name ?: '#'.$r->id }}</div>
                                    <div style="display:inline-block; margin-top:0.15rem; font-size:0.55rem; font-weight:700; color:{{ $color }}; padding:1px 6px; border-radius:99px; background:{{ $bg }}; border:1px solid {{ $color }}55; letter-spacing:0.02em; white-space:nowrap;">{{ $title }}</div>
                                    <div style="font-size:0.55rem; color:#7a7a82; display:flex; align-items:center; gap:0.2rem; margin-top:0.15rem;">
                                        @if ($r->alliance_id)
                                            <img src="/img/alliance/{{ $r->alliance_id }}?size=32" loading="lazy" referrerpolicy="no-referrer" alt="" style="width:10px; height:10px;">
                                        @endif
                                        <span>{{ $r->alliance_name ?: '—' }}</span>
                                    </div>
                                </div>
                                <div style="flex:0 0 64px; text-align:right; font-size:0.7rem; font-weight:700; color:{{ $color }};">{{ $valFmt }}</div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if (! empty($lb['most_valuable']))
        <div data-tab="top-kills" style="margin-bottom:1rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(255,255,255,0.02);">
            <div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.6rem; flex-wrap:wrap;">
                <h2 id="sec-top-kills" style="margin:0; font-size:0.85rem; color:#e5e5e7; scroll-margin-top: 60px;">Top 10 most valuable single kills</h2>
                <span style="font-size:0.6rem; color:#7a7a82;">conflict-wide · click row → zKill</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:0.4rem;">
                @foreach ($lb['most_valuable'] as $i => $m)
                    @php
                        $sideTint = $m->side === 'wc' ? '#6dd6ff' : ($m->side === 'hostile' ? $opposing_tint : '#9ca3af');
                        $sideLbl = $m->side === 'wc' ? 'WinterCo' : ($m->side === 'hostile' ? $opposing_label : '—');
                    @endphp
                    @php
                        $shipUrl = $shipIcon((int) ($m->victim_ship_type_id ?? 0), 64);
                        $allyUrl = $allianceIcon((int) ($m->victim_alliance_id ?? 0), 64);
                    @endphp
                    <div style="position:relative; padding:0.5rem 0.7rem; border:1px solid rgba(255,255,255,0.06); border-radius:5px; background:rgba(0,0,0,0.20);">
                        <a href="/kills/{{ $m->killmail_id }}" style="display:flex; gap:0.5rem; text-decoration:none; color:inherit;">
                            @if ($shipUrl)
                                <img src="{{ $shipUrl }}" loading="lazy" referrerpolicy="no-referrer" alt=""
                                     class="aegis-icon aegis-icon-ship-md" style="width:32px; height:32px; flex:0 0 32px; align-self:center;">
                            @endif
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; align-items:baseline; gap:0.4rem; flex-wrap:wrap;">
                                    <span style="font-size:0.55rem; color:#7a7a82; min-width:14px;">#{{ $i + 1 }}</span>
                                    <span style="font-size:1rem; font-weight:700; color:#f4c75c;">{{ $fmtIsk((float) $m->total_value) }}</span>
                                    <span style="font-size:0.55rem; color:{{ $sideTint }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $sideLbl }}</span>
                                </div>
                                <div style="font-size:0.7rem; color:#cbd5e1; margin-top:0.15rem;">{{ $m->victim_ship_type_name ?: 'Unknown' }} <span style="color:#7a7a82;">· {{ $m->victim_name ?: '—' }}</span></div>
                                <div style="font-size:0.6rem; color:#7a7a82; margin-top:0.1rem; display:flex; align-items:center; gap:0.25rem;">
                                    @if ($allyUrl)
                                        <img src="{{ $allyUrl }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="aegis-icon aegis-icon-ally">
                                    @endif
                                    <span>{{ $m->victim_alliance_name ?: '—' }}</span>
                                    <span>· {{ $m->system_name }} · {{ \Carbon\Carbon::parse($m->killed_at)->format('M d H:i') }}</span>
                                </div>
                            </div>
                        </a>
                        <a href="https://zkillboard.com/kill/{{ $m->killmail_id }}/" target="_blank" rel="noopener"
                           title="Open on zKillboard"
                           style="position:absolute; top:0.4rem; right:0.5rem; font-size:0.55rem; color:#7a7a82; text-decoration:none;">zkill ↗</a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Pilot + alliance leaderboards. Split: pilots tab gets the
         first 2 boards, alliances tab the last 2. Same render loop
         for both. --}}
    @if (! empty($lb))
        @php
            $tabbedBoards = [
                ['tab' => 'pilots',    'list' => [
                    ['title' => 'Top 10 pilots — kills',     'rows' => $lb['top_pilots_kills'] ?? [],   'metric' => 'kills',  'second' => 'isk_fb',   'second_fmt' => 'isk', 'tint' => '#6dd6ff', 'sub_label' => 'fb isk'],
                    ['title' => 'Top 10 pilots — losses',    'rows' => $lb['top_pilots_losses'] ?? [],  'metric' => 'losses', 'second' => 'isk_lost', 'second_fmt' => 'isk', 'tint' => '#c474a8', 'sub_label' => 'isk lost'],
                ]],
                ['tab' => 'alliances', 'list' => [
                    ['title' => 'Top 10 alliances — kills',  'rows' => $lb['top_alliance_kills'] ?? [],  'metric' => 'kills',  'second' => null,        'second_fmt' => null,  'tint' => '#6dd6ff', 'sub_label' => null],
                    ['title' => 'Top 10 alliances — losses', 'rows' => $lb['top_alliance_losses'] ?? [], 'metric' => 'losses', 'second' => 'isk_lost',  'second_fmt' => 'isk', 'tint' => '#c474a8', 'sub_label' => 'isk lost'],
                ]],
            ];
        @endphp
        @foreach ($tabbedBoards as $tg)
        <div data-tab="{{ $tg['tab'] }}" id="sec-{{ $tg['tab'] }}" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:0.6rem; margin-bottom:1rem; scroll-margin-top: 60px;">
            @foreach ($tg['list'] as $b)
                @if (count($b['rows']) === 0) @continue @endif
                @php
                    $maxMetric = 1;
                    foreach ($b['rows'] as $row) $maxMetric = max($maxMetric, (int) $row->{$b['metric']});
                @endphp
                <div style="padding:0.65rem 0.8rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(0,0,0,0.20);">
                    <h3 style="margin:0 0 0.4rem 0; font-size:0.72rem; color:{{ $b['tint'] }}; letter-spacing:0.04em;">{{ $b['title'] }}</h3>
                    @foreach ($b['rows'] as $i => $row)
                        @php
                            $w = max(2, (int) round(((int) $row->{$b['metric']} / $maxMetric) * 100));
                            $isAlliance = str_contains((string) $b['title'], 'alliances');
                            $iconUrl = $isAlliance
                                ? $allianceIcon((int) ($row->id ?? 0), 64)
                                : $charIcon((int) ($row->id ?? 0), 64);
                            $allyIconUrl = $allianceIcon((int) ($row->alliance_id ?? 0), 64);
                        @endphp
                        <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.62rem; padding:0.18rem 0; border-bottom:1px solid rgba(255,255,255,0.04);">
                            <span style="flex:0 0 14px; color:#7a7a82; font-size:0.55rem;">{{ $i + 1 }}</span>
                            @if ($iconUrl)
                                <img src="{{ $iconUrl }}" loading="lazy" referrerpolicy="no-referrer" alt=""
                                     class="aegis-icon {{ $isAlliance ? 'aegis-icon-ally-md' : 'aegis-icon-char-md' }}">
                            @endif
                            <div style="flex:1; min-width:0;">
                                <div style="color:#e5e5e7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="{{ $row->name }}">{{ $row->name }}</div>
                                @if (isset($row->alliance_name))
                                    <div style="color:#7a7a82; font-size:0.55rem; display:flex; align-items:center; gap:0.25rem;">
                                        @if (! $isAlliance && $allyIconUrl)
                                            <img src="{{ $allyIconUrl }}" loading="lazy" referrerpolicy="no-referrer" alt=""
                                                 class="aegis-icon aegis-icon-ally">
                                        @endif
                                        <span>{{ $row->alliance_name }}</span>
                                    </div>
                                @endif
                            </div>
                            <div style="flex:0 0 70px;">
                                <div style="height:8px; background:rgba(255,255,255,0.04); border-radius:2px; overflow:hidden;">
                                    <div style="height:100%; width:{{ $w }}%; background:{{ $b['tint'] }}; opacity:0.65;"></div>
                                </div>
                            </div>
                            <div style="flex:0 0 38px; text-align:right; color:#e5e5e7; font-weight:600;">{{ $fmtNum($row->{$b['metric']}) }}</div>
                            @if ($b['second'] !== null)
                                <div style="flex:0 0 56px; text-align:right; color:#f4c75c; font-size:0.55rem;">{{ $fmtIsk((float) ($row->{$b['second']} ?? 0)) }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
        @endforeach
    @endif

    {{-- Top implant losses (capsule kills with non-zero value) --}}
    @if (count($top_implant_pods ?? []) > 0)
        <div data-tab="implants" style="margin-bottom:1rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(255,255,255,0.02);">
            <div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.6rem; flex-wrap:wrap;">
                <h2 id="sec-implants" style="margin:0; font-size:0.85rem; color:#e5e5e7; scroll-margin-top: 60px;">Top implant losses</h2>
                <span style="font-size:0.6rem; color:#7a7a82;">biggest pods by destroyed-implant value · pods with total_value=0 are clean clones (excluded)</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:0.4rem;">
                @foreach ($top_implant_pods as $p)
                    @php
                        $sideTint = $p->side === 'wc' ? '#6dd6ff' : ($p->side === 'hostile' ? $opposing_tint : '#9ca3af');
                    @endphp
                    <div style="position:relative; padding:0.5rem 0.7rem; border:1px solid rgba(255,255,255,0.06); border-radius:5px; background:rgba(0,0,0,0.20);">
                        <a href="/kills/{{ $p->killmail_id }}" style="display:block; text-decoration:none; color:inherit;">
                            <div style="display:flex; align-items:baseline; gap:0.5rem;">
                                <span style="font-size:0.95rem; font-weight:700; color:#f4c75c;">{{ $fmtIsk((float) $p->total_value) }}</span>
                                <span style="font-size:0.55rem; color:{{ $sideTint }}; text-transform:uppercase; letter-spacing:0.06em;">{{ $p->side === 'wc' ? 'WinterCo' : ($p->side === 'hostile' ? $opposing_label : '—') }}</span>
                            </div>
                            <div style="font-size:0.65rem; color:#cbd5e1; margin-top:0.15rem;">{{ $p->victim_name ?: 'unknown pilot' }} <span style="color:#7a7a82;">· {{ $p->victim_alliance_name ?: '—' }}</span></div>
                            <div style="font-size:0.6rem; color:#7a7a82; margin-top:0.1rem;">{{ $p->system_name }} · {{ \Carbon\Carbon::parse($p->killed_at)->format('M d H:i') }}</div>
                        </a>
                        <a href="https://zkillboard.com/kill/{{ $p->killmail_id }}/" target="_blank" rel="noopener"
                           title="Open on zKillboard"
                           style="position:absolute; top:0.4rem; right:0.5rem; font-size:0.55rem; color:#7a7a82; text-decoration:none;">zkill ↗</a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Upwell structure timeline --}}
    @if (count($structures) > 0)
        <div data-tab="structures" style="margin-bottom:1rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(255,255,255,0.02);">
            <div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.6rem;">
                <h2 id="sec-structures" style="margin:0; font-size:0.85rem; color:#e5e5e7; scroll-margin-top: 60px;">Upwell structure timeline</h2>
                <span style="font-size:0.6rem; color:#7a7a82;">every structure killmail in the conflict ({{ count($structures) }} total)</span>
            </div>
            <div style="max-height:340px; overflow-y:auto; border:1px solid rgba(255,255,255,0.04); border-radius:5px;">
                <table style="width:100%; font-size:0.7rem; border-collapse:collapse;">
                    <thead style="position:sticky; top:0; background:#0a0d12; z-index:1;">
                        <tr style="text-transform:uppercase; letter-spacing:0.06em; font-size:0.55rem; color:#7a7a82;">
                            <th style="padding:0.4rem 0.6rem; text-align:left;">When</th>
                            <th style="padding:0.4rem 0.6rem; text-align:left;">System</th>
                            <th style="padding:0.4rem 0.6rem; text-align:left;">Side</th>
                            <th style="padding:0.4rem 0.6rem; text-align:left;">Type</th>
                            <th style="padding:0.4rem 0.6rem; text-align:left;">Owner</th>
                            <th style="padding:0.4rem 0.6rem; text-align:right;">ISK</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($structures as $s)
                            @php
                                $sideColor = $s->side === 'wc' ? '#6dd6ff' : ($s->side === 'hostile' ? $opposing_tint : '#9ca3af');
                                $sideLbl = $s->side === 'wc' ? 'WinterCo' : ($s->side === 'hostile' ? $opposing_label : '—');
                            @endphp
                            <tr style="border-top:1px solid rgba(255,255,255,0.04);">
                                <td style="padding:0.35rem 0.6rem; color:#cbd5e1; white-space:nowrap;">{{ \Carbon\Carbon::parse($s->killed_at)->format('M d H:i') }}</td>
                                <td style="padding:0.35rem 0.6rem; color:#7dd3fc;">{{ $s->system_name }}</td>
                                <td style="padding:0.35rem 0.6rem; color:{{ $sideColor }}; font-size:0.6rem; text-transform:uppercase; letter-spacing:0.06em;">{{ $sideLbl }}</td>
                                <td style="padding:0.35rem 0.6rem; color:#f4c75c;">{{ $s->victim_ship_type_name ?: $s->victim_ship_group_name ?: 'Structure' }}</td>
                                <td style="padding:0.35rem 0.6rem; color:#cbd5e1;">{{ $s->victim_alliance_name ?: $s->victim_corp_name ?: '—' }}</td>
                                <td style="padding:0.35rem 0.6rem; text-align:right; color:#f4c75c; white-space:nowrap;">
                                    <a href="/kills/{{ $s->killmail_id }}" style="color:inherit; text-decoration:none; font-weight:600;">{{ $fmtIsk((float) $s->total_value) }}</a>
                                    <a href="https://zkillboard.com/kill/{{ $s->killmail_id }}/" target="_blank" rel="noopener" title="Open on zKillboard" style="margin-left:0.35rem; font-size:0.5rem; color:#7a7a82; text-decoration:none;">↗</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @php
        // Helpers used by the per-side histogram blocks below.
        $maxOf = function (array $rows, string $key): float {
            $m = 0.0;
            foreach ($rows as $r) {
                $v = (float) ($r->{$key} ?? 0);
                if ($v > $m) $m = $v;
            }
            return $m > 0 ? $m : 1.0;
        };
        $barCellW = '8px'; // daily-activity bar fixed cell width
    @endphp

    {{-- Per-side breakdown panels — histograms instead of raw lists --}}
    <div data-tab="side-breakdowns" style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:0.75rem;">
        @foreach ($sideKeys as $key)
            @php
                $col = $tiles[$key];
                $r = $rollups[$key] ?? ['daily' => [], 'ship_groups' => [], 'alliances' => [], 'systems' => [], 'hour_of_day' => []];
                $maxDay = $maxOf($r['daily'], 'kms');
                $maxShip = $maxOf($r['ship_groups'], 'kms');
                $maxAlly = $maxOf($r['alliances'], 'kms');
                $maxSys = $maxOf($r['systems'], 'kms');
                $maxHour = $maxOf($r['hour_of_day'], 'kms');
                $hourMap = [];
                foreach ($r['hour_of_day'] as $h) $hourMap[(int) $h->hr] = (int) $h->kms;
                $recentRows = $recent[$key] ?? [];
            @endphp
            <div style="padding:0.7rem 0.85rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(0,0,0,0.22);">
                {{-- Header --}}
                <div style="display:flex; align-items:baseline; justify-content:space-between; margin-bottom:0.5rem; padding-bottom:0.4rem; border-bottom:1px solid rgba(255,255,255,0.06);">
                    <h3 style="margin:0; font-size:0.85rem; color:{{ $col['tint'] }}; letter-spacing:0.04em;">{{ $col['label'] }}</h3>
                    <div style="font-size:0.6rem; color:#7a7a82;">
                        <strong style="color:#e5e5e7;">{{ $fmtNum($col['count']) }}</strong> kms ·
                        <strong style="color:#f4c75c;">{{ $fmtIsk((float) $col['isk']) }}</strong>
                    </div>
                </div>

                {{-- Daily activity histogram. min-width:0 + flex:1 1
                     0 keeps the row from overflowing its parent card
                     when the conflict has many days (vs-initiative
                     has 211 days × 3px = 633px > narrow side panel).
                     box-sizing:border-box on the wrapper so the 2px
                     padding doesn't push it past 100% width. --}}
                <div style="margin-bottom:0.65rem;">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.25rem;">Daily activity (kms)</div>
                    <div style="display:flex; align-items:flex-end; height:54px; gap:1px; background:rgba(0,0,0,0.30); padding:2px; border-radius:3px; width:100%; box-sizing:border-box; overflow:hidden;">
                        @foreach ($r['daily'] as $d)
                            @php
                                $h = (int) round(((int) $d->kms / $maxDay) * 50);
                                $h = max(1, $h);
                                $iskFmt = $fmtIsk((float) $d->isk);
                            @endphp
                            <div title="{{ $d->day }} · {{ $fmtNum($d->kms) }} kms · {{ $iskFmt }}"
                                 style="flex:1 1 0; min-width:0; height:{{ $h }}px; background:{{ $col['tint'] }}; opacity:0.85; border-radius:1px 1px 0 0;"></div>
                        @endforeach
                    </div>
                    @if (count($r['daily']) > 0)
                        <div style="display:flex; justify-content:space-between; font-size:0.5rem; color:#7a7a82; margin-top:0.2rem;">
                            <span>{{ $r['daily'][0]->day }}</span>
                            <span>{{ end($r['daily'])->day }}</span>
                        </div>
                    @endif
                </div>

                {{-- Hour-of-day histogram --}}
                <div style="margin-bottom:0.65rem;">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.25rem;">Hour of day (UTC)</div>
                    <div style="display:flex; align-items:flex-end; height:34px; gap:1px; width:100%; box-sizing:border-box; overflow:hidden;">
                        @for ($hr = 0; $hr < 24; $hr++)
                            @php
                                $v = $hourMap[$hr] ?? 0;
                                $h = $v > 0 ? max(2, (int) round(($v / $maxHour) * 30)) : 1;
                            @endphp
                            <div title="{{ sprintf('%02d:00', $hr) }} · {{ $fmtNum($v) }} kms"
                                 style="flex:1 1 0; min-width:0; height:{{ $h }}px; background:{{ $col['tint'] }}; opacity:{{ $v > 0 ? '0.85' : '0.18' }}; border-radius:1px 1px 0 0;"></div>
                        @endfor
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:0.5rem; color:#7a7a82; margin-top:0.15rem;">
                        <span>00</span><span>06</span><span>12</span><span>18</span><span>23</span>
                    </div>
                </div>

                {{-- Ship-group breakdown — caps/supers/titans pinned
                     to the top via priority field on the SQL row
                     (ORDER BY priority ASC, kms DESC). Strategic
                     classes always visible, the long subcap tail
                     scrolls inside a fixed-height container. --}}
                <div style="margin-bottom:0.65rem;">
                    <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.3rem;">Ship classes lost</div>
                    <div style="max-height:340px; overflow-y:auto; padding-right:0.25rem;">
                        @foreach ($r['ship_groups'] as $g)
                            @php
                                $w = max(2, (int) round(((int) $g->kms / $maxShip) * 100));
                                $prio = (int) ($g->priority ?? 4);
                                $isPinned = $prio <= 3;
                            @endphp
                            <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.62rem; margin-bottom:0.15rem;{{ $isPinned ? ' background:rgba(253,224,71,0.05); border-left:2px solid #f4c75c; padding:1px 0 1px 4px;' : '' }}">
                                <div style="flex:0 0 92px; color:{{ $isPinned ? '#f4c75c' : '#cbd5e1' }}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="{{ $g->label }}">{{ $g->label }}</div>
                                <div style="flex:1; height:11px; background:rgba(255,255,255,0.04); border-radius:2px; overflow:hidden;">
                                    <div style="height:100%; width:{{ $w }}%; background:{{ $col['tint'] }}; opacity:0.7;"></div>
                                </div>
                                <div style="flex:0 0 38px; text-align:right; color:#e5e5e7; font-weight:600;">{{ $fmtNum($g->kms) }}</div>
                                <div style="flex:0 0 56px; text-align:right; color:#f4c75c; font-size:0.58rem;">{{ $fmtIsk((float) $g->isk) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Top victim alliances --}}
                @if (count($r['alliances']) > 0)
                    <div style="margin-bottom:0.65rem;">
                        <div style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:0.3rem;">Victim alliances</div>
                        @foreach ($r['alliances'] as $a)
                            @php $w = max(2, (int) round(((int) $a->kms / $maxAlly) * 100)); @endphp
                            <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.62rem; margin-bottom:0.15rem;">
                                <div style="flex:0 0 110px; color:#cbd5e1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="{{ $a->label }}">{{ $a->label }}</div>
                                <div style="flex:1; height:11px; background:rgba(255,255,255,0.04); border-radius:2px; overflow:hidden;">
                                    <div style="height:100%; width:{{ $w }}%; background:{{ $col['tint'] }}; opacity:0.65;"></div>
                                </div>
                                <div style="flex:0 0 40px; text-align:right; color:#e5e5e7; font-weight:600;">{{ $fmtNum($a->kms) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Recent feed (last 15) — compact tactical-log
                     row: ship+pilot icons stacked left, ship/system
                     in middle, victim/FB stacked right of that, ISK
                     value + timecode pinned to the right edge.   --}}
                @if (count($recentRows) > 0)
                    <details open style="margin-top:0.4rem;" class="wr-killfeed">
                        <summary>Recent {{ count($recentRows) }} losses</summary>
                        <div class="wr-killfeed-body">
                            @foreach ($recentRows as $rr)
                                @php
                                    $isPod = in_array((int) $rr->victim_ship_type_id, [670, 33328], true);
                                    $isCleanPod = $isPod && (float) $rr->total_value <= 0.0;
                                    $rrShip = $shipIcon((int) ($rr->victim_ship_type_id ?? 0), 64);
                                    $rrChar = $charIcon((int) ($rr->victim_character_id ?? 0), 64);
                                @endphp
                                <a href="/kills/{{ $rr->killmail_id }}" class="wr-killrow {{ $isPod ? 'is-pod' : '' }} {{ $isCleanPod ? 'is-clean-pod' : '' }}">
                                    <div class="wr-kr-icons">
                                        @if ($rrShip)<img src="{{ $rrShip }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="wr-kr-ship">@endif
                                        @if ($rrChar)<img src="{{ $rrChar }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="wr-kr-char">@endif
                                    </div>
                                    <div class="wr-kr-ship-cell">
                                        <span class="wr-kr-ship-name">{{ $rr->victim_ship_type_name ?: ($isPod ? 'Capsule' : '—') }}</span>
                                        <span class="wr-kr-system">{{ $rr->system_name }}</span>
                                    </div>
                                    <div class="wr-kr-victim-cell">
                                        <span class="wr-kr-victim">{{ $rr->victim_name ?: 'unknown pilot' }}</span>
                                        <span class="wr-kr-alliance">{{ $rr->victim_alliance_name ?: 'no alliance' }}</span>
                                        @if ($rr->fb_char_name)
                                            <span class="wr-kr-fb">FB {{ $rr->fb_char_name }}@if ($rr->fb_alliance_name) · {{ $rr->fb_alliance_name }}@endif</span>
                                        @endif
                                    </div>
                                    <div class="wr-kr-meta">
                                        @if ($isCleanPod)
                                            <span class="wr-kr-isk wr-kr-clean" title="Clean clone — no implants destroyed.">CLEAN&nbsp;CLONE</span>
                                        @else
                                            <span class="wr-kr-isk">{{ $fmtIsk((float) $rr->total_value) }}</span>
                                        @endif
                                        <span class="wr-kr-time">{{ \Carbon\Carbon::parse($rr->killed_at)->format('H:i · M d') }}</span>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Top systems (where each side died) — full-width footer
         section. Per-side block was misaligning the per-side panel
         heights, so rendered here as a single row mirroring the
         upwell-structure timeline style. --}}
    <div data-tab="side-breakdowns" style="margin-top:1rem; padding:0.85rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:8px; background:rgba(255,255,255,0.02);">
        <div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.6rem; flex-wrap:wrap;">
            <h2 id="sec-side-breakdown" style="margin:0; font-size:0.85rem; color:#e5e5e7; scroll-margin-top: 60px;">Top systems by side losses</h2>
            <span style="font-size:0.6rem; color:#7a7a82;">where each side died · entire conflict</span>
        </div>
        <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:0.6rem;">
            @foreach ($sideKeys as $key)
                @php
                    $col = $tiles[$key];
                    $r = $rollups[$key] ?? ['systems' => []];
                    $sysRows = $r['systems'] ?? [];
                    $maxSys = $maxOf($sysRows, 'kms');
                @endphp
                <div style="padding:0.55rem 0.7rem; border:1px solid rgba(255,255,255,0.06); border-radius:5px; background:rgba(0,0,0,0.20);">
                    <div style="font-size:0.6rem; color:{{ $col['tint'] }}; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:0.35rem;">{{ $col['label'] }}</div>
                    @if (count($sysRows) === 0)
                        <p style="font-size:0.65rem; color:#9ca3af; font-style:italic;">No data.</p>
                    @else
                        @foreach ($sysRows as $s)
                            @php
                                $w = max(2, (int) round(((int) $s->kms / $maxSys) * 100));
                                $sysColor = $sevColor($s->security_status ?? null);
                            @endphp
                            <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.62rem; margin-bottom:0.15rem;">
                                <div style="flex:0 0 70px; color:{{ $sysColor }}; font-weight:600;">{{ $s->label }}</div>
                                <div style="flex:1; height:11px; background:rgba(255,255,255,0.04); border-radius:2px; overflow:hidden;">
                                    <div style="height:100%; width:{{ $w }}%; background:{{ $col['tint'] }}; opacity:0.55;"></div>
                                </div>
                                <div style="flex:0 0 40px; text-align:right; color:#e5e5e7; font-weight:600;">{{ $fmtNum($s->kms) }}</div>
                                <div style="flex:0 0 56px; text-align:right; color:#f4c75c; font-size:0.58rem;">{{ $fmtIsk((float) $s->isk) }}</div>
                            </div>
                        @endforeach
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Fixed-bottom hot-kills ticker — always visible while scrolling.
         Spacer above keeps the last section from sliding under it. --}}
    @php $ticker = $ticker_kills ?? []; @endphp
    @if (count($ticker) > 0)
        <div style="height:54px;"></div>{{-- spacer matching the fixed ticker height --}}
        <div class="aegis-ticker-fixed">
            <div class="aegis-ticker-fixed-label">
                <span style="color:#f4c75c;">⚡</span>
                <span>Hot kills · last 24h</span>
            </div>
            <div class="aegis-ticker">
                <div class="aegis-ticker-track">
                    @foreach (array_merge($ticker, $ticker) as $t)
                        @php
                            $tShip = $shipIcon((int) ($t->victim_ship_type_id ?? 0), 64);
                            $tAlly = $allianceIcon((int) ($t->victim_alliance_id ?? 0), 64);
                        @endphp
                        <a href="/kills/{{ $t->killmail_id }}" class="aegis-ticker-item">
                            @if ($tShip)<img src="{{ $tShip }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="aegis-icon aegis-icon-ship">@endif
                            <span style="color:#f4c75c; font-weight:700;">{{ $fmtIsk((float) $t->total_value) }}</span>
                            <span style="color:#cbd5e1;">{{ $t->victim_ship_type_name ?: '?' }}</span>
                            <span style="color:#7dd3fc;">{{ $t->system_name }}</span>
                            <span style="color:#9ca3af; font-size:0.55rem;">{{ $t->victim_name ?: '—' }}</span>
                            @if ($tAlly)<img src="{{ $tAlly }}" loading="lazy" referrerpolicy="no-referrer" alt="" class="aegis-icon aegis-icon-ally">@endif
                            <span style="color:#7a7a82; font-size:0.55rem;">{{ $t->victim_alliance_name ?: '—' }}</span>
                            <span style="color:#7a7a82; font-size:0.5rem;">{{ \Carbon\Carbon::parse($t->killed_at)->format('H:i') }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
        <style>
            .aegis-ticker-fixed {
                position: fixed;
                bottom: 0; left: 0; right: 0;
                z-index: 90;
                display: flex;
                align-items: center;
                gap: 0.6rem;
                padding: 0.45rem 0.75rem;
                background: rgba(5, 7, 9, 0.92);
                border-top: 1px solid rgba(253, 224, 71, 0.25);
                box-shadow: 0 -4px 18px rgba(0, 0, 0, 0.55);
                backdrop-filter: blur(6px);
                -webkit-backdrop-filter: blur(6px);
            }
            .aegis-ticker-fixed-label {
                flex: 0 0 auto;
                font-size: 0.55rem;
                color: #7a7a82;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                white-space: nowrap;
                padding-right: 0.6rem;
                border-right: 1px solid rgba(255,255,255,0.08);
            }
            .aegis-ticker { flex: 1 1 auto; min-width: 0; overflow: hidden; }
            .aegis-ticker-track {
                display: flex;
                gap: 1.6rem;
                animation: aegis-ticker-scroll 90s linear infinite;
                will-change: transform;
            }
            .aegis-ticker:hover .aegis-ticker-track { animation-play-state: paused; }
            .aegis-ticker-item {
                display: inline-flex;
                align-items: baseline;
                gap: 0.4rem;
                padding: 0.15rem 0.5rem;
                font-size: 0.7rem;
                white-space: nowrap;
                text-decoration: none;
                color: inherit;
                border-left: 2px solid rgba(253, 224, 71, 0.20);
            }
            .aegis-ticker-item:hover { background: rgba(253, 224, 71, 0.06); }
            @keyframes aegis-ticker-scroll {
                from { transform: translateX(0); }
                to   { transform: translateX(-50%); }
            }
        </style>
    @endif

