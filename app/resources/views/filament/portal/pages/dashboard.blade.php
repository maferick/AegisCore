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
