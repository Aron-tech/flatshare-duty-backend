<?php

it('renders the public pages in both languages', function (string $path, string $lang, string $text) {
    $this->get("{$path}?lang={$lang}")->assertOk()->assertSee($text);
})->with([
    ['/', 'hu', 'Hamarosan'],
    ['/', 'en', 'Coming soon'],
    ['/privacy', 'hu', 'Adatvédelmi nyilatkozat'],
    ['/privacy', 'en', 'Privacy policy'],
    ['/account-deletion', 'hu', 'Fiók és adatok törlése'],
    ['/account-deletion', 'en', 'Delete your account and data'],
]);

it('links the stores once their addresses are configured', function () {
    config(['app.store_links' => ['app_store' => 'https://apps.apple.com/app/id1', 'google_play' => 'https://play.google.com/store/apps/details?id=x']]);

    $this->get('/')->assertOk()
        ->assertSee('https://apps.apple.com/app/id1')
        ->assertDontSee('Hamarosan');
});

it('uses the browser language without a parameter', function () {
    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')->get('/privacy')->assertSee('Privacy policy');
});

it('describes the page for search engines and AI assistants', function () {
    $this->get('/?lang=en')->assertOk()
        ->assertSee('<link rel="canonical" href="'.url('/?lang=en').'">', false)
        ->assertSee('hreflang="hu" href="'.url('/?lang=hu').'"', false)
        ->assertSee('hreflang="x-default" href="'.url('/').'"', false)
        ->assertSee('property="og:image" content="'.asset('og-image-en.png').'"', false)
        ->assertSee('"@type":"FAQPage"', false)
        ->assertSee('"@type":"MobileApplication"', false)
        ->assertSee('What is FlatShare?');
});

it('keeps the browser-language versions apart for caches', function () {
    $this->get('/')->assertHeader('Vary', 'Accept-Language');
});

it('lets search engines and AI crawlers in, but not the API', function () {
    $this->get('/robots.txt')->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('User-agent: GPTBot')
        ->assertSee('User-agent: Google-Extended')
        ->assertSee('Disallow: /api/')
        ->assertSee('Sitemap: '.url('/sitemap.xml'));
});

it('lists every public page in both languages in the sitemap', function () {
    $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $sitemap = simplexml_load_string($response->getContent());
    expect($sitemap->url)->toHaveCount(6);
    $response->assertSee('<loc>'.url('/privacy?lang=en').'</loc>', false);
});

it('summarises the app for AI assistants in llms.txt', function () {
    $this->get('/llms.txt')->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertSee('# FlatShare')
        ->assertSee('What is FlatShare?')
        ->assertSee('Mi az a FlatShare?');
});
