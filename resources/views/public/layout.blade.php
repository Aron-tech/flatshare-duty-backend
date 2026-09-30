<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($locale = app()->getLocale())
    @php($routeName = request()->route()?->getName() ?? 'landing')
    @php($languages = array_column(\App\Enums\LanguageEnum::cases(), 'value'))
    @php($ogLocales = ['hu' => 'hu_HU', 'en' => 'en_US'])
    @php($pageTitle = trim($__env->yieldContent('title', __('public.meta_title'))))
    @php($pageDescription = trim($__env->yieldContent('description', __('public.meta_description'))))
    @php($canonical = route($routeName, ['lang' => $locale]))
    @php($ogImage = asset("og-image-{$locale}.png"))
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <link rel="canonical" href="{{ $canonical }}">
    @foreach ($languages as $language)
        <link rel="alternate" hreflang="{{ $language }}" href="{{ route($routeName, ['lang' => $language]) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ route($routeName) }}">
    <meta property="og:site_name" content="FlatShare">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ $ogLocales[$locale] }}">
    @foreach (array_diff($languages, [$locale]) as $language)
        <meta property="og:locale:alternate" content="{{ $ogLocales[$language] }}">
    @endforeach
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ __('public.og_image_alt') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">
    <meta name="theme-color" content="#FDFBF7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1C1B1A" media="(prefers-color-scheme: dark)">
    <link rel="alternate" type="text/markdown" title="llms.txt" href="{{ route('llms') }}">
    @yield('structured_data')
    <link rel="icon" href="data:image/svg+xml,{{ rawurlencode(view('public.partials.emblem', ['size' => 64])->render()) }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/public.min.css') }}?v={{ filemtime(public_path('css/public.min.css')) }}">
    @stack('styles')
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a class="brand" href="{{ route('landing', ['lang' => app()->getLocale()]) }}">
                @include('public.partials.emblem', ['size' => 32])
                FlatShare
            </a>
            <nav class="nav">
                @yield('nav')
                <a class="lang" href="?lang={{ __('public.switch_language_code') }}" hreflang="{{ __('public.switch_language_code') }}">{{ __('public.switch_language') }}</a>
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="container">
            <div>© {{ date('Y') }} FlatShare. {{ __('public.footer_rights') }}</div>
            <nav>
                <a href="{{ route('privacy', ['lang' => app()->getLocale()]) }}">{{ __('public.footer_privacy') }}</a>
                <a href="{{ route('account-deletion', ['lang' => app()->getLocale()]) }}">{{ __('public.footer_deletion') }}</a>
                <a href="mailto:{{ config('app.contact_email') }}">{{ __('public.footer_contact') }}</a>
            </nav>
        </div>
    </footer>
</body>
</html>
