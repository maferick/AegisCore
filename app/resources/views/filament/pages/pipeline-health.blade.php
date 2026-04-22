<x-filament-panels::page>
    @php
        $labels = [
            'ingest_lag'       => 'Ingest lag',
            'content_lag'      => 'Content lag',
            'r2z2_cursor'      => 'R2Z2 cursor',
            'shells'           => 'Shell rows (7d)',
            'enrich_backlog'   => 'Enrich backlog',
            'cluster_lag'      => 'Cluster lag',
            'horizon_queues'   => 'Horizon queues',
            'failed_jobs'      => 'Failed jobs (24h)',
            'neo4j_edges'      => 'Neo4j allegiance',
            'market_history'  => 'Market history',
            'hub_catchments'  => 'Hub catchments',
            'esi_backlog'      => 'ESI name cache',
            'corp_history'     => 'Char → corp history',
            'alliance_history' => 'Corp → alliance history',
            'opensearch_docs'  => 'OpenSearch docs',
            'battle_pipeline'  => 'Battle pipeline',
            'combat_anomalies' => 'Combat anomalies',
            'personal_orders'  => 'Personal orders',
        ];
        $levelColors = [
            'ok'   => ['border' => '#22c55e', 'bg' => 'rgba(34,197,94,0.06)',  'fg' => '#86efac', 'dot' => '#22c55e'],
            'warn' => ['border' => '#e5a900', 'bg' => 'rgba(229,169,0,0.06)', 'fg' => '#fcd34d', 'dot' => '#e5a900'],
            'down' => ['border' => '#ef4444', 'bg' => 'rgba(239,68,68,0.06)', 'fg' => '#fca5a5', 'dot' => '#ef4444'],
        ];
        $overallColor = $levelColors[$overall] ?? $levelColors['warn'];
    @endphp

    <style>
        .ph-wrap { font-family: 'Inter', sans-serif; color: #e5e5e7; }
        .ph-header { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1rem;
                     padding:0.7rem 1rem; border:1px solid #26262b; border-radius:6px; background:rgba(17,17,19,0.6); }
        .ph-status-dot { width:10px; height:10px; border-radius:50%; display:inline-block; }
        .ph-header .title { font-size:0.95rem; font-weight:600; }
        .ph-header .sub { color:#7a7a82; font-size:0.78rem; }
        .ph-header .right { margin-left:auto; color:#7a7a82; font-size:0.72rem; font-family:'JetBrains Mono',monospace; }

        .ph-section { margin-bottom:1.2rem; }
        .ph-section-head { display:flex; align-items:center; gap:0.5rem; margin-bottom:0.45rem; padding-bottom:0.25rem;
                           border-bottom:1px solid #26262b; font-size:0.62rem; text-transform:uppercase;
                           letter-spacing:0.1em; color:#7a7a82; font-weight:600; }

        .ph-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:0.6rem; }

        .ph-tile { position:relative; background:rgba(17,17,19,0.6); border:1px solid #26262b;
                   border-left-width:3px; border-radius:5px; padding:0.65rem 0.8rem; min-height:68px; }
        .ph-tile .label { font-size:0.58rem; letter-spacing:0.1em; text-transform:uppercase;
                          color:rgba(229,229,231,0.5); font-family:'JetBrains Mono',monospace; }
        .ph-tile .value { font-size:0.95rem; font-weight:600; color:#e5e5e7; margin-top:0.2rem;
                          font-family:'JetBrains Mono',monospace; font-variant-numeric:tabular-nums;
                          line-height:1.25; word-break:break-word; }
        .ph-tile .badge { position:absolute; top:0.5rem; right:0.65rem; font-size:0.55rem; font-weight:700;
                          letter-spacing:0.1em; text-transform:uppercase; padding:1px 5px; border-radius:2px; }
        .ph-tile .stats-row { display:flex; gap:0.8rem; margin-top:0.35rem; font-size:0.64rem; color:#7a7a82; font-family:'JetBrains Mono',monospace; }
        .ph-tile .stats-row span { white-space:nowrap; }
    </style>

    <div class="ph-wrap">
        <div class="ph-header" style="border-left:3px solid {{ $overallColor['border'] }};">
            <span class="ph-status-dot" style="background:{{ $overallColor['dot'] }};"></span>
            <div>
                <div class="title">Pipeline health · <span style="color:{{ $overallColor['fg'] }};text-transform:uppercase;letter-spacing:0.08em;font-size:0.8rem;">{{ $overall }}</span></div>
                <div class="sub">Ingest, enrichment, clustering, and derived-store throughput in one view.</div>
            </div>
            <div class="right">
                snapshot @ {{ $computed_at }}
                <br>
                <span style="color:#4fd0d0;">wire:poll.30s</span>
            </div>
        </div>

        @foreach ($sections as $sec)
            <div class="ph-section">
                <div class="ph-section-head">
                    <x-filament::icon :icon="$sec['icon']" class="h-3.5 w-3.5" />
                    <span>{{ $sec['title'] }}</span>
                </div>
                <div class="ph-grid">
                    @foreach ($sec['keys'] as $k)
                        @php
                            $m = $snapshot[$k] ?? null;
                            $label = $labels[$k] ?? $k;
                            $lvl = $m['level'] ?? 'warn';
                            $c = $levelColors[$lvl] ?? $levelColors['warn'];
                            $detail = $m['detail'] ?? 'n/a';
                        @endphp
                        <div class="ph-tile" style="border-left-color:{{ $c['border'] }};background:{{ $c['bg'] }};">
                            <span class="badge" style="background:{{ $c['bg'] }};color:{{ $c['fg'] }};border:1px solid {{ $c['border'] }};">{{ $lvl }}</span>
                            <div class="label">{{ $label }}</div>
                            <div class="value">{{ $detail }}</div>
                            @if ($k === 'battle_pipeline' && $m)
                                <div class="stats-row">
                                    <span>pending: {{ number_format((int) ($m['pending'] ?? 0)) }}</span>
                                    <span>rate: {{ number_format((int) ($m['done_last_1h'] ?? 0)) }}/hr</span>
                                    @if (! empty($m['eta_hours']))
                                        <span>ETA: {{ $m['eta_hours'] }}h</span>
                                    @endif
                                </div>
                            @elseif ($k === 'enrich_backlog' && ! empty($m['by_month']))
                                <div class="stats-row">
                                    @foreach ($m['by_month'] as $bm)
                                        <span>{{ $bm['month'] }}: {{ number_format($bm['n']) }}</span>
                                    @endforeach
                                </div>
                            @elseif ($k === 'horizon_queues' && ! empty($m['queues']))
                                <div class="stats-row">
                                    @foreach ($m['queues'] as $qname => $qlen)
                                        <span>{{ str_replace('queues:', '', $qname) }}: {{ number_format($qlen) }}</span>
                                    @endforeach
                                </div>
                            @elseif ($k === 'combat_anomalies' && $m && ! empty($m['total']))
                                <div class="stats-row">
                                    <span style="color:#fca5a5;">R: {{ $m['reinforces'] ?? 0 }}</span>
                                    <span style="color:#86efac;">W: {{ $m['weakens'] ?? 0 }}</span>
                                    <span>I: {{ $m['insufficient'] ?? 0 }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <div wire:poll.30s></div>
</x-filament-panels::page>
