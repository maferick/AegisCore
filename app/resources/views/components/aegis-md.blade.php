@props([
    'body' => null,
])
@once
    @push('styles')
    @endpush
@endonce
@php
    $rendered = $body === null || $body === ''
        ? ''
        : (string) \Illuminate\Support\Str::markdown((string) $body);
@endphp
<style>
    .aegis-md h1, .aegis-md h2 { font-size: 1.05rem; color: #e5e5e7; margin: 1rem 0 0.4rem; font-weight: 600; }
    .aegis-md h3 { font-size: 0.95rem; color: #cbd5e1; margin: 0.8rem 0 0.3rem; font-weight: 600; }
    .aegis-md h4 { font-size: 0.85rem; color: #cbd5e1; margin: 0.6rem 0 0.25rem; font-weight: 600; }
    .aegis-md p { margin: 0.4rem 0; }
    .aegis-md ul, .aegis-md ol { margin: 0.4rem 0 0.4rem 1.4rem; }
    .aegis-md li { margin: 0.15rem 0; }
    .aegis-md code { background: rgba(255,255,255,0.06); padding: 1px 5px; border-radius: 3px; font-size: 0.78rem; }
    .aegis-md pre { background: rgba(0,0,0,0.18); padding: 0.5rem 0.7rem; border-radius: 4px; overflow:auto; }
    .aegis-md a { color: #7dd3fc; text-decoration: underline; }
    .aegis-md strong { color: #f1f5f9; }
    .aegis-md hr { border: 0; border-top: 1px solid rgba(255,255,255,0.10); margin: 0.8rem 0; }
    .aegis-md blockquote { border-left: 3px solid rgba(255,255,255,0.15); padding-left: 0.7rem; color: #94a3b8; margin: 0.5rem 0; }
    .aegis-md table { border-collapse: collapse; margin: 0.5rem 0; font-size: 0.78rem; }
    .aegis-md th, .aegis-md td { padding: 4px 8px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .aegis-md th { background: rgba(255,255,255,0.04); font-weight: 600; }
</style>
<div {{ $attributes->merge(['class' => 'aegis-md']) }} style="font-size:0.85rem; color:#e2e8f0; line-height:1.6;">
    {!! $rendered !!}
</div>
