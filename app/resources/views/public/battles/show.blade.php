@extends('public.layout', ['page_class' => 'battles'])

@section('title', 'Battle in '.($theater->primarySystem?->name ?? '#'.$theater->primary_system_id))

@php
    // LCP candidate = the first side's anchor alliance logo at top of
    // the report. Preload it so it begins fetching before the HTML
    // parser hits the <img> tag — saves seconds on slow connections.
    $lcpAllianceId = (int) ($flagship_logos['A']['alliance_id'] ?? $flagship_logos['B']['alliance_id'] ?? 0);
@endphp
@if ($lcpAllianceId)
    @push('head')
        <link rel="preload" as="image"
              href="https://images.evetech.net/alliances/{{ $lcpAllianceId }}/logo?size=64"
              fetchpriority="high">
        <link rel="preconnect" href="https://images.evetech.net" crossorigin>
    @endpush
@endif

@section('content')
    {{-- Same rollup the authed Filament page renders, minus the portal
         chrome + the bloc-name subtitles (hidden by $hide_bloc_names=true
         via the controller). --}}
    @include('partials.battle-theater-body')
@endsection
