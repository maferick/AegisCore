<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $display_label ?? 'War Report' }} — killsineve.online</title>
    <meta name="description" content="Live war report — {{ $display_label ?? 'WinterCo vs Imperium' }}. Conflict floor 2026-04-02.">
    <meta name="robots" content="index, follow">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='13' font-size='14'%3E%E2%9A%94%3C/text%3E%3C/svg%3E">
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Inter, system-ui, sans-serif;
            background: #050709;
            color: #e5e5e7;
            font-size: 0.85rem;
            line-height: 1.45;
        }
        .container {
            max-width: 1480px;
            margin: 0 auto;
            padding: 1.5rem 1.25rem 3rem;
            position: relative;
            z-index: 1;
        }
        .public-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.5rem 0 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .public-header h1 {
            margin: 0;
            font-size: 1rem;
            color: #e5e5e7;
            letter-spacing: 0.04em;
            font-weight: 600;
        }
        .public-header .meta {
            font-size: 0.65rem;
            color: #7a7a82;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        a { color: inherit; }
        code {
            background: rgba(255, 255, 255, 0.04);
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 0.7em;
        }
    </style>
    @include('partials.aegis-public-bg')
</head>
<body class="aegis-public-bg" data-page="{{ $conflict_key ?? 'war-report' }}">
    {{-- Watermark logos — Fraternity (anchor of WinterCo, the panda
         coalition) on the left, opposing-bloc anchor on the right.
         Pulled via the local /img proxy so the page renders without
         hitting CCP for them. --}}
    @php
        $opposingAnchorId = match ($conflict_key ?? '') {
            'vs-imperium'   => 1354830081,  // Goonswarm Federation (bee)
            'vs-initiative' =>  1900696668, // The Initiative.
            default => null,
        };
    @endphp
    @if ($opposingAnchorId)
        <div class="aegis-watermarks" aria-hidden="true">
            <img class="aegis-watermark left"  src="/img/alliance/99003581?size=512" alt="" referrerpolicy="no-referrer">
            <img class="aegis-watermark right" src="/img/alliance/{{ $opposingAnchorId }}?size=512" alt="" referrerpolicy="no-referrer">
        </div>
    @endif
    <div class="container">
        <div class="public-header">
            <h1>⚔ {{ $display_label ?? 'War Report' }}</h1>
            <span class="meta"><a href="/war-report" style="color:#7dd3fc; text-decoration:none;">← all conflicts</a></span>
        </div>
        @include('partials.war-report-body')
    </div>
</body>
</html>
