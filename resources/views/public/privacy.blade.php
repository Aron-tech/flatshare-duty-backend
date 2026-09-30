@extends('public.layout')

@section('title', __('public.privacy_title').' – FlatShare')
@section('description', __('public.privacy_description'))

@section('content')
    <article class="legal">
        <a class="back" href="{{ route('landing', ['lang' => app()->getLocale()]) }}">{{ __('public.back_home') }}</a>
        @include('public.privacy.'.app()->getLocale(), ['email' => config('app.contact_email')])
    </article>
@endsection
