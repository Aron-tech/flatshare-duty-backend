# FlatShare

> {{ __('public.meta_description', [], 'en') }}

FlatShare is a mobile app (iOS and Android) that helps people who share a home split the housework fairly. It is available in English and Hungarian. Website: {{ url('/') }}

## Links

- [Home page (English)]({{ route('landing', ['lang' => 'en']) }})
- [Kezdőlap (magyar)]({{ route('landing', ['lang' => 'hu']) }})
- [Privacy policy]({{ route('privacy', ['lang' => 'en']) }})
- [Delete your account]({{ route('account-deletion', ['lang' => 'en']) }})
@foreach (array_filter(config('app.store_links', [])) as $store => $link)
- [{{ $store === 'app_store' ? 'App Store' : 'Google Play' }}]({{ $link }})
@endforeach
- Contact: {{ config('app.contact_email') }}

## Features

@foreach (['fair', 'points', 'rewards', 'offer', 'calendar', 'stickers'] as $key)
- **{{ __("public.feature_{$key}_title", [], 'en') }}**: {{ __("public.feature_{$key}_text", [], 'en') }}
@endforeach

## How it works

@foreach (range(1, 6) as $step)
{{ $step }}. **{{ __("public.how_{$step}_title", [], 'en') }}**: {{ __("public.how_{$step}_text", [], 'en') }}
@endforeach

## FAQ

@foreach (__('public.faq', [], 'en') as $item)
### {{ $item['q'] }}

{{ $item['a'] }}

@endforeach
## Magyarul

{{ __('public.meta_description', [], 'hu') }}

@foreach (__('public.faq', [], 'hu') as $item)
### {{ $item['q'] }}

{{ $item['a'] }}

@endforeach
