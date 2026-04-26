@props([
    'surface' => 'incident',
    'timestamp' => null,
    'persisted' => null,
    'windowStart' => null,
    'windowEnd' => null,
])
{!! \App\Services\IntelFreshness::pill($surface, $timestamp, $persisted, $windowStart, $windowEnd) !!}
