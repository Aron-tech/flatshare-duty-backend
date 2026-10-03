@extends('public.layout')

@php($storeLinks = array_values(array_filter(config('app.store_links', []))))
@php($faq = __('public.faq'))

@section('structured_data')
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => url('/').'#organization',
                    'name' => 'FlatShare',
                    'url' => url('/'),
                    'logo' => asset('og-image-'.app()->getLocale().'.png'),
                    'email' => config('app.contact_email'),
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => url('/').'#website',
                    'name' => 'FlatShare',
                    'url' => url('/'),
                    'inLanguage' => ['hu', 'en'],
                    'publisher' => ['@id' => url('/').'#organization'],
                ],
                array_filter([
                    '@type' => 'MobileApplication',
                    '@id' => url('/').'#app',
                    'name' => 'FlatShare',
                    'description' => __('public.meta_description'),
                    'url' => route('landing', ['lang' => app()->getLocale()]),
                    'image' => asset('og-image-'.app()->getLocale().'.png'),
                    'operatingSystem' => 'iOS, Android',
                    'applicationCategory' => 'LifestyleApplication',
                    'inLanguage' => ['hu', 'en'],
                    'featureList' => array_map(fn (string $key): string => __("public.feature_{$key}_title").': '.__("public.feature_{$key}_text"), ['fair', 'points', 'rewards', 'offer', 'calendar', 'stickers']),
                    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
                    'installUrl' => $storeLinks ?: null,
                    'publisher' => ['@id' => url('/').'#organization'],
                ]),
                [
                    '@type' => 'FAQPage',
                    '@id' => route('landing', ['lang' => app()->getLocale()]).'#faq',
                    'inLanguage' => app()->getLocale(),
                    'mainEntity' => array_map(fn (array $item): array => [
                        '@type' => 'Question',
                        'name' => $item['q'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                    ], $faq),
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
    </script>
@endsection

@section('nav')
    <a class="section-link" href="#features">{{ __('public.nav_features') }}</a>
    <a class="section-link" href="#how">{{ __('public.nav_how') }}</a>
    <a class="section-link" href="#faq">{{ __('public.nav_faq') }}</a>
    <a class="section-link" href="#download">{{ __('public.nav_download') }}</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/landing.min.css') }}?v={{ filemtime(public_path('css/landing.min.css')) }}">
@endpush

@section('content')
    <section class="hero">
        <div class="container">
            <div class="hero-copy">
                <span class="eyebrow">{{ __('public.hero_eyebrow') }}</span>
                <h1>{!! __('public.hero_title') !!}</h1>
                <p class="lead">{{ __('public.hero_lead') }}</p>
                @include('public.partials.store-buttons')
            </div>

            {{-- Mirrors the app's Home screen (flatshare-client: app/(tabs)/index.tsx). --}}
            <div class="phone-float">
                <div class="phone" aria-hidden="true" data-demo>
                    <div class="phone-screen">
                        <div class="app-head">
                            <div>
                                <strong>{{ __('public.mock_greeting') }}</strong>
                                <small>{{ __('public.mock_weekday') }} • {!! __('public.mock_cadence', ['count' => '<span data-open>3</span>']) !!}</small>
                            </div>
                            <span class="sprout"><svg class="ic" viewBox="0 0 24 24"><path d="M7 20h10"/><path d="M10 20c5.5-2.5.8-6.4 3-10"/><path d="M9.5 9.4c1.1.8 1.8 2.2 2.3 3.7-2 .4-3.5.4-4.8-.3-1.2-.6-2.3-1.9-3-4.2 2.8-.5 4.4 0 5.5.8z"/><path d="M14.1 6a7 7 0 0 0-1.1 4c1.9-.1 3.3-.6 4.3-1.4 1-1 1.6-2.3 1.7-4.6-2.7.1-4 1-4.9 2z"/></svg></span>
                        </div>

                        <div class="goal">
                            <div class="goal-top">
                                <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg><span class="muted-label">{{ __('public.mock_goal') }}</span></span>
                                <span class="pill pill-primary">{{ __('public.mock_days_left') }}</span>
                            </div>
                            <div class="goal-nums">
                                <span><b data-points>84</b><em>{{ __('public.mock_of_points') }}</em></span>
                                <span>{!! __('public.mock_percent', ['percent' => '<span data-percent>70</span>']) !!}</span>
                            </div>
                            <div class="bar"><i data-bar style="width: 70%"></i></div>
                            <div class="goal-foot">
                                <span class="pill pill-success">{{ __('public.mock_on_pace') }}</span>
                                <span>{{ __('public.mock_balance') }}</span>
                            </div>
                            <div class="spendable">{{ __('public.mock_spendable') }} <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></div>
                        </div>

                        <div class="segments">
                            <span class="active">{{ __('public.mock_tab_mine') }} <i data-open>3</i></span>
                            <span>{{ __('public.mock_tab_pool') }} <i>2</i></span>
                        </div>

                        <div class="list-head">
                            <span class="muted-label">{{ __('public.mock_section') }}</span>
                            <span>{{ __('public.mock_section_hint') }}</span>
                        </div>

                        @php($mockTasks = [
                            ['key' => 1, 'color' => '#F59E0B', 'icon' => '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>', 'points' => 15],
                            ['key' => 2, 'color' => '#6B7280', 'icon' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><path d="M10 11v6M14 11v6"/>', 'points' => 8],
                        ])
                        @foreach ($mockTasks as $task)
                            <div class="task" @if ($loop->first) data-demo-task @endif>
                                <div class="task-body">
                                    <span class="cat" style="background: {{ $task['color'] }}26; color: {{ $task['color'] }}"><svg class="ic" viewBox="0 0 24 24">{!! $task['icon'] !!}</svg></span>
                                    <div class="task-text">
                                        <span class="muted-label">{{ __("public.mock_task_{$task['key']}_category") }}</span>
                                        <b>{{ __("public.mock_task_{$task['key']}") }}</b>
                                        <div class="task-meta">
                                            <span class="due"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>{{ __("public.mock_task_{$task['key']}_due") }}</span>
                                            <span><svg class="ic" viewBox="0 0 24 24"><path d="M10 2h4"/><path d="m12 14 3-3"/><circle cx="12" cy="14" r="8"/></svg>{{ __("public.mock_task_{$task['key']}_minutes") }}</span>
                                            <span class="pill pill-success">{{ __('public.mock_plus_points', ['count' => $task['points']]) }}</span>
                                        </div>
                                        <span class="task-link"><svg class="ic" viewBox="0 0 24 24"><path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/></svg>{{ __('public.mock_swap') }}</span>
                                    </div>
                                </div>
                                <span class="check-btn">
                                    <svg class="ic ic-open" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                                    <svg class="ic ic-done" viewBox="0 0 24 24"><path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/></svg>
                                </span>
                                @if ($loop->first)
                                    <span class="toast">{{ __('public.mock_plus_points', ['count' => $task['points']]) }}</span>
                                @endif
                            </div>
                        @endforeach

                        <div class="screen-fade"></div>
                        <div class="tabbar">
                            <span class="active"><svg class="ic" viewBox="0 0 24 24"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>{{ __('public.mock_nav_home') }}</span>
                            <span><svg class="ic" viewBox="0 0 24 24"><path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/></svg>{{ __('public.mock_nav_chores') }}</span>
                            <span class="add"><b><svg class="ic" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M12 5v14"/></svg></b></span>
                            <span><svg class="ic" viewBox="0 0 24 24"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>{{ __('public.mock_nav_stats') }}</span>
                            <span><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/></svg>{{ __('public.mock_nav_rewards') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="block" id="features">
        <div class="container">
            <div class="section-head reveal">
                <h2>{{ __('public.features_title') }}</h2>
                <p>{{ __('public.features_lead') }}</p>
            </div>
            @php($features = [
                'fair' => '<path d="M12 3v18M5 7h14M5 7l-3 7a4 4 0 0 0 6 0L5 7Zm14 0-3 7a4 4 0 0 0 6 0l-3-7Z"/>',
                'points' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
                'rewards' => '<path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7Zm0 0h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7Z"/>',
                'offer' => '<path d="M17 2l4 4-4 4M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4M21 13v2a3 3 0 0 1-3 3H3"/>',
                'calendar' => '<rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18"/>',
                'stickers' => '<path d="M15.5 3H6a3 3 0 0 0-3 3v12a3 3 0 0 0 3 3h9l6-6V6a3 3 0 0 0-3-3h-2.5Z"/><path d="M15 21v-4a2 2 0 0 1 2-2h4"/>',
            ])
            <div class="features">
                @foreach ($features as $key => $icon)
                    <article class="feature reveal" style="--i: {{ $loop->index }}">
                        <div class="icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon !!}</svg></div>
                        <h3>{{ __("public.feature_{$key}_title") }}</h3>
                        <p>{{ __("public.feature_{$key}_text") }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="block" id="how">
        <div class="container">
            <div class="section-head reveal">
                <h2>{{ __('public.how_title') }}</h2>
                <p>{{ __('public.how_lead') }}</p>
            </div>
            <div class="steps">
                @foreach (range(1, 6) as $step)
                    <article class="step reveal" style="--i: {{ $loop->index % 3 }}">
                        <h3>{{ __("public.how_{$step}_title") }}</h3>
                        <p>{{ __("public.how_{$step}_text") }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="block" id="faq">
        <div class="container">
            <div class="section-head reveal">
                <h2>{{ __('public.faq_title') }}</h2>
                <p>{{ __('public.faq_lead') }}</p>
            </div>
            <div class="faq">
                @foreach ($faq as $item)
                    <details class="reveal" style="--i: {{ $loop->index % 3 }}" @if ($loop->first) open @endif>
                        <summary>{{ $item['q'] }}</summary>
                        <p>{{ $item['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    <section id="download">
        <div class="container">
            <div class="cta reveal">
                <div class="emblem-lg">@include('public.partials.emblem', ['size' => 64])</div>
                <h2>{{ __('public.cta_title') }}</h2>
                <p>{{ __('public.cta_text') }}</p>
                @include('public.partials.store-buttons')
            </div>
        </div>
    </section>

    <script>
        (() => {
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const revealed = document.querySelectorAll('.reveal');

            if (reduceMotion || !('IntersectionObserver' in window)) {
                revealed.forEach((element) => element.classList.add('is-visible'));
            } else {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            observer.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                revealed.forEach((element) => observer.observe(element));
            }

            const demo = document.querySelector('[data-demo]');
            if (!demo || reduceMotion) {
                return;
            }

            // Same as in the app: completing the dishes (+15) takes 84/120 to 99/120.
            const goal = 120;
            const start = 84;
            const reward = 15;
            const task = demo.querySelector('[data-demo-task]');
            const bar = demo.querySelector('[data-bar]');
            const pointsLabel = demo.querySelector('[data-points]');
            const percentLabel = demo.querySelector('[data-percent]');
            const openLabels = demo.querySelectorAll('[data-open]');

            const countTo = (from, to, duration) => {
                const started = performance.now();
                const step = (now) => {
                    const progress = Math.min(1, Math.max(0, (now - started) / duration));
                    const eased = 1 - Math.pow(1 - progress, 3);
                    const value = Math.round(from + (to - from) * eased);
                    pointsLabel.textContent = value;
                    percentLabel.textContent = Math.min(100, Math.round((value / goal) * 100));
                    if (progress < 1) {
                        requestAnimationFrame(step);
                    }
                };
                requestAnimationFrame(step);
                bar.style.width = Math.min(100, Math.round((to / goal) * 100)) + '%';
            };

            const setOpen = (count) => openLabels.forEach((label) => { label.textContent = count; });

            const play = () => {
                task.classList.remove('is-done');
                setOpen(3);
                bar.style.width = '0%';
                void bar.offsetWidth;
                countTo(0, start, 1400);

                setTimeout(() => {
                    task.classList.add('is-done');
                    setOpen(2);
                    countTo(start, start + reward, 900);
                }, 3600);
            };

            new IntersectionObserver((entries, observer) => {
                if (entries[0].isIntersecting) {
                    observer.disconnect();
                    play();
                    setInterval(play, 10000);
                }
            }).observe(demo);
        })();
    </script>
@endsection
