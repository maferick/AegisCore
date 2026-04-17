@extends('public.layout')

@section('title', 'Battle in '.($theater->primarySystem?->name ?? '#'.$theater->primary_system_id))

@section('content')
    {{-- Same rollup the authed Filament page renders, minus the portal
         chrome + the bloc-name subtitles (hidden by $hide_bloc_names=true
         via the controller). --}}
    @include('partials.battle-theater-body')
@endsection
