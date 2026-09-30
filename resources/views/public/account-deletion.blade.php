@extends('public.layout')

@section('title', __('public.deletion_title').' – FlatShare')
@section('description', __('public.deletion_description'))

@section('content')
    <article class="legal">
        <a class="back" href="{{ route('landing', ['lang' => app()->getLocale()]) }}">{{ __('public.back_home') }}</a>
        <h1>{{ __('public.deletion_title') }}</h1>
        <p>{{ __('public.deletion_lead') }}</p>

        <div class="card">
            <h2 style="margin-top: 0">{{ __('public.deletion_in_app_title') }}</h2>
            <ol>
                @foreach (__('public.deletion_in_app_steps') as $step)
                    <li>{!! $step !!}</li>
                @endforeach
            </ol>
        </div>

        <div class="card">
            <h2 style="margin-top: 0">{{ __('public.deletion_email_title') }}</h2>
            <p style="margin-bottom: 0">{!! __('public.deletion_email_text', ['email' => '<a href="mailto:'.e(config('app.contact_email')).'?subject='.rawurlencode(__('public.footer_deletion')).'">'.e(config('app.contact_email')).'</a>']) !!}</p>
        </div>

        <h2>{{ __('public.deletion_what_title') }}</h2>
        <ul>
            @foreach (__('public.deletion_deleted') as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>

        <h2>{{ __('public.deletion_kept_title') }}</h2>
        <p>{{ __('public.deletion_kept_text') }}</p>
    </article>
@endsection
