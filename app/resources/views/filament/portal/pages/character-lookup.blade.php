<x-filament-panels::page>
    {{-- Reuse the same pop-out CSS/JS the Dashboard defines so the
         lazy-loaded map on the looked-up character behaves the same. --}}
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

    {{-- Search bar --}}
    <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
        <form method="get" style="display:flex; gap:0.5rem; align-items:center;">
            <input type="text" name="q" value="{{ $search }}"
                   placeholder="Search character name (3+ chars)…"
                   style="flex:1; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); color:#e5e5e7; padding:0.5rem 0.75rem; border-radius:4px; font-size:0.85rem;">
            <button type="submit" style="background:#4338ca; color:#fff; border:none; padding:0.5rem 1rem; border-radius:4px; font-size:0.8rem; cursor:pointer;">Search</button>
            @if ($character_id || $search)
                <a href="?" style="font-size:0.75rem; color:#7a7a82;">clear</a>
            @endif
        </form>
        @if (! empty($suggestions))
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:0.35rem; margin-top:0.75rem;">
                @foreach ($suggestions as $s)
                    <a href="?cid={{ $s['character_id'] }}"
                       style="text-decoration:none; display:flex; gap:0.5rem; align-items:center;
                              background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08);
                              border-radius:5px; padding:0.4rem 0.6rem; color:#e5e5e7; font-size:0.8rem;">
                        <img src="https://images.evetech.net/characters/{{ $s['character_id'] }}/portrait?size=32"
                             referrerpolicy="no-referrer" style="width:22px;height:22px;border-radius:50%;" alt="">
                        <span>{{ $s['name'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @if ($character_id && $card)
        @if (! empty($data_since))
            <div style="font-size:0.7rem; color:#7a7a82; margin-bottom:0.75rem; font-style:italic;">
                Kill + ISK stats cover killmails since
                <span style="color:#cbd5e1;">{{ \Carbon\Carbon::parse($data_since)->format('Y-m-d') }}</span>
                (our data floor). Earlier activity not counted.
            </div>
        @endif
        @include('filament.portal.partials.character-card', ['c' => $card])
    @elseif ($character_id && ! $card)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Character #{{ $character_id }} not found in our ESI name cache.
            </p>
        </div>
    @endif
</x-filament-panels::page>
