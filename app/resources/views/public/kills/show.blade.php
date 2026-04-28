@extends('public.layout', ['page_class' => 'kill'])

@section('title', ($km->victim_ship_type_name ?? 'Kill').' — Kill #'.$km->killmail_id)

@section('content')
    @include('partials.killmail-body')
@endsection
