@php($app_store_url = config('app.store_links.app_store'))
@php($google_play_url = config('app.store_links.google_play'))
<div class="store-buttons">
    <a class="btn store-btn {{ $app_store_url ? '' : 'is-soon' }}" href="{{ $app_store_url ?: '#' }}" @if(! $app_store_url) aria-disabled="true" @endif>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.37 12.64c-.02-2.33 1.9-3.45 1.99-3.5-1.08-1.59-2.77-1.8-3.37-1.83-1.43-.15-2.8.84-3.52.84-.73 0-1.85-.82-3.04-.8-1.56.02-3 .91-3.8 2.31-1.62 2.82-.41 6.98 1.17 9.26.77 1.12 1.69 2.37 2.9 2.33 1.16-.05 1.6-.75 3.01-.75 1.4 0 1.8.75 3.03.73 1.25-.02 2.04-1.14 2.81-2.26.88-1.3 1.25-2.55 1.27-2.62-.03-.01-2.43-.93-2.45-3.71ZM14.06 5.8c.64-.78 1.07-1.85.95-2.93-.92.04-2.04.61-2.7 1.39-.59.69-1.11 1.79-.97 2.84 1.03.08 2.07-.52 2.72-1.3Z"/></svg>
        <div><small>{{ $app_store_url ? __('public.app_store_small') : __('public.store_soon') }}</small><span>{{ __('public.app_store') }}</span></div>
    </a>
    <a class="btn store-btn {{ $google_play_url ? '' : 'is-soon' }}" href="{{ $google_play_url ?: '#' }}" @if(! $google_play_url) aria-disabled="true" @endif>
        <svg width="22" height="24" viewBox="0 0 22 24" fill="currentColor" aria-hidden="true"><path d="M1.2.5 12.5 11.9 1.2 23.4a1.6 1.6 0 0 1-.7-1.4V1.9c0-.6.3-1.1.7-1.4Zm12.6 12.7 2.9 2.9-12.9 7.3 10-10.2Zm4.4-4.3 3 1.7c.9.5.9 1.8 0 2.3l-3 1.7-3.2-3.2 3.2-2.5ZM3.8.6l12.9 7.3-2.9 2.9L3.8.6Z"/></svg>
        <div><small>{{ $google_play_url ? __('public.google_play_small') : __('public.store_soon') }}</small><span>{{ __('public.google_play') }}</span></div>
    </a>
</div>
