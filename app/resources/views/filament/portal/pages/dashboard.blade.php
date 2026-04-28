<x-filament-panels::page>
    {{-- Region-panel pop-out styles + click handler — lives in the
         parent page so it runs on initial load, not inside the
         innerHTML-injected map partial (innerHTML doesn't execute
         script tags). --}}
    <style>
        .wc-region-panel { cursor: zoom-in; transition: box-shadow 0.15s; }
        .wc-region-panel:hover { box-shadow: 0 0 0 1px rgba(79,208,208,0.4); }
        .wc-region-panel.wc-pop-open {
            position: fixed; inset: 3vh 3vw; z-index: 100;
            background: #050709; border: 1px solid rgba(79,208,208,0.35);
            padding: 1rem; overflow: auto; cursor: zoom-out;
            box-shadow: 0 20px 60px rgba(0,0,0,0.7);
        }
        .wc-region-panel.wc-pop-open svg { min-height: 85vh !important; }
        body.wc-map-pop-active { overflow: hidden; }
        body.wc-map-pop-active::before {
            content: ''; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 99;
        }
    </style>
    <script>
        (function () {
            if (window.__wcMapPopBound) return;
            window.__wcMapPopBound = true;
            document.addEventListener('click', function (e) {
                var panel = e.target.closest('.wc-region-panel');
                if (!panel) return;
                panel.classList.toggle('wc-pop-open');
                document.body.classList.toggle('wc-map-pop-active',
                    !!document.querySelector('.wc-region-panel.wc-pop-open'));
            });
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                document.querySelectorAll('.wc-region-panel.wc-pop-open').forEach(function (el) {
                    el.classList.remove('wc-pop-open');
                });
                document.body.classList.remove('wc-map-pop-active');
            });
        })();
    </script>
    @php
        $fmtIsk = function (float $v): string {
            if ($v >= 1e12) return number_format($v / 1e12, 2) . ' T';
            if ($v >= 1e9)  return number_format($v / 1e9, 2) . ' B';
            if ($v >= 1e6)  return number_format($v / 1e6, 2) . ' M';
            if ($v >= 1e3)  return number_format($v / 1e3, 1) . ' K';
            return number_format($v, 0);
        };
    @endphp

    {{-- War report — centered, large, glowing call-out. Active conflict
         (WinterCo vs Goons + Init) is the operator's primary frame
         right now, so the entry point lives at the top of every
         dashboard load above the per-character cards. --}}
    <div style="display:flex; justify-content:center; margin:1.25rem 0 1.5rem 0;">
        <a href="/portal/war-report" class="aegis-war-link">
            <span class="aegis-war-link-icon">⚔</span>
            <span class="aegis-war-link-text">
                <span class="aegis-war-link-title">War Reports</span>
                <span class="aegis-war-link-sub">WinterCo vs Imperium · WinterCo vs Initiative · since 2026-04-02</span>
            </span>
        </a>
    </div>
    <style>
        .aegis-war-link {
            display:inline-flex; align-items:center; gap:0.85rem;
            padding:0.85rem 1.6rem;
            border-radius:10px;
            text-decoration:none;
            background:linear-gradient(135deg, rgba(34,197,94,0.10) 0%, rgba(0,0,0,0.55) 50%, rgba(239,68,68,0.14) 100%);
            border:1px solid rgba(253, 224, 71, 0.35);
            color:#fde68a;
            box-shadow: 0 0 24px rgba(253,224,71,0.30), 0 0 48px rgba(253,224,71,0.12);
            transition: box-shadow 0.25s, transform 0.15s;
            animation: aegis-war-glow 2.6s ease-in-out infinite;
        }
        .aegis-war-link:hover {
            box-shadow: 0 0 32px rgba(253,224,71,0.55), 0 0 72px rgba(253,224,71,0.25);
            transform: translateY(-1px);
        }
        .aegis-war-link-icon {
            font-size:1.7rem; line-height:1;
            text-shadow: 0 0 12px rgba(253,224,71,0.7);
        }
        .aegis-war-link-text { display:flex; flex-direction:column; gap:0.1rem; }
        .aegis-war-link-title {
            font-size:1.15rem; font-weight:700; letter-spacing:0.06em;
            text-transform:uppercase; color:#fef3c7;
        }
        .aegis-war-link-sub {
            font-size:0.7rem; color:#cbd5e1; letter-spacing:0.02em;
        }
        @keyframes aegis-war-glow {
            0%   { box-shadow: 0 0 24px rgba(253,224,71,0.30), 0 0 48px rgba(253,224,71,0.12); }
            50%  { box-shadow: 0 0 32px rgba(253,224,71,0.50), 0 0 64px rgba(253,224,71,0.22); }
            100% { box-shadow: 0 0 24px rgba(253,224,71,0.30), 0 0 48px rgba(253,224,71,0.12); }
        }
    </style>

    @if (! empty($data_since))
        <div style="font-size:0.7rem; color:#7a7a82; margin-bottom:0.75rem; font-style:italic;">
            Kill + ISK stats cover killmails since
            <span style="color:#cbd5e1;">{{ \Carbon\Carbon::parse($data_since)->format('Y-m-d') }}</span>
            (our data floor). Earlier activity not counted.
        </div>
    @endif

    @if (empty($characters))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                No EVE character linked yet. Link one via <a href="/portal/account-settings" class="text-primary-500 underline">Account settings</a>.
            </p>
        </div>
    @endif

    @foreach ($characters as $c)
        @include("filament.portal.partials.character-card", ["c" => $c])
    @endforeach
</x-filament-panels::page>
