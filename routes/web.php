<?php

use App\Enums\LanguageEnum;
use App\Http\Middleware\SetPublicPageLocaleMiddleware;
use Illuminate\Support\Facades\Route;

// The public pages of the app: the store listings link the privacy policy and the account deletion page.
Route::middleware(SetPublicPageLocaleMiddleware::class)->group(function (): void {
    Route::view('/', 'public.landing')->name('landing');
    Route::view('/privacy', 'public.privacy')->name('privacy');
    Route::view('/account-deletion', 'public.account-deletion')->name('account-deletion');
});

Route::redirect('/adatvedelem', '/privacy?lang=hu');
Route::redirect('/fiok-torles', '/account-deletion?lang=hu');

// For search engines and AI assistants (Google, Gemini, ChatGPT…): crawl rules, the list of the public pages and a plain summary of the app.
Route::get('/robots.txt', fn () => response()->view('public.seo.robots')->header('Content-Type', 'text/plain; charset=UTF-8'))->name('robots');
Route::get('/sitemap.xml', fn () => response()->view('public.seo.sitemap', [
    'languages' => array_column(LanguageEnum::cases(), 'value'),
    'lastModified' => date('Y-m-d', max(array_map('filemtime', [...glob(resource_path('views/public/*.blade.php')), ...glob(lang_path('*/public.php'))]))),
])->header('Content-Type', 'application/xml; charset=UTF-8'))->name('sitemap');
Route::get('/llms.txt', fn () => response()->view('public.seo.llms')->header('Content-Type', 'text/markdown; charset=UTF-8'))->name('llms');
