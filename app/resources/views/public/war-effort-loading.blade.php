<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="refresh" content="1;url=/war-report/{{ $conflict }}/me">
    <title>Loading your war effort…</title>
    <style>
        :root { color-scheme: dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #050709;
            color: #e5e5e7;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Inter, system-ui, sans-serif;
        }
        .panel {
            text-align: center;
            padding: 2.5rem 3rem;
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 12px;
            background: rgba(0,0,0,0.40);
            box-shadow: 0 0 32px {{ $opposing_tint }};
        }
        .spinner {
            margin: 0 auto 1.2rem;
            width: 56px;
            height: 56px;
            border: 4px solid rgba(255,255,255,0.10);
            border-top-color: {{ $opposing_tint }};
            border-radius: 50%;
            animation: aegis-spin 1.1s linear infinite;
        }
        @keyframes aegis-spin {
            to { transform: rotate(360deg); }
        }
        h1 { margin: 0 0 0.5rem 0; font-size: 1.05rem; letter-spacing: 0.04em; font-weight: 700; }
        p  { margin: 0; font-size: 0.78rem; color: #9ca3af; }
        a  { color: #7dd3fc; text-decoration: none; }
    </style>
    @include('partials.aegis-public-bg')
</head>
<body class="aegis-public-bg" data-page="{{ $page_class }}">
    <div class="panel">
        <div class="spinner"></div>
        <h1>Calculating your war effort…</h1>
        <p>Crunching ~100k killmails. This page auto-refreshes; if it doesn't, <a href="/war-report/{{ $conflict }}/me">click here</a>.</p>
    </div>
</body>
</html>
