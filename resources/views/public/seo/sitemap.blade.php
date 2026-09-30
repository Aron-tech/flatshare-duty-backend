{!! "<"."?xml version=\"1.0\" encoding=\"UTF-8\"?".">" !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach (['landing', 'privacy', 'account-deletion'] as $routeName)
@foreach ($languages as $language)
    <url>
        <loc>{{ route($routeName, ['lang' => $language]) }}</loc>
        <lastmod>{{ $lastModified }}</lastmod>
@foreach ($languages as $alternate)
        <xhtml:link rel="alternate" hreflang="{{ $alternate }}" href="{{ route($routeName, ['lang' => $alternate]) }}"/>
@endforeach
        <xhtml:link rel="alternate" hreflang="x-default" href="{{ route($routeName) }}"/>
    </url>
@endforeach
@endforeach
</urlset>
