<?php

namespace App\Http\Middleware;

use App\Enums\LanguageEnum;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public pages (landing, privacy policy, account deletion) are in Hungarian by default,
 * the ?lang= parameter or the browser's language can switch them to English.
 */
class SetPublicPageLocaleMiddleware
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $language = LanguageEnum::tryFrom((string) $request->query('lang'))
            ?? LanguageEnum::fromLocale((string) $request->getPreferredLanguage(array_column(LanguageEnum::cases(), 'value')))
            ?? LanguageEnum::HUNGARIAN;

        app()->setLocale($language->value);

        $response = $next($request);

        // Without ?lang= the language comes from the browser, so caches and crawlers must keep the versions apart.
        if (! $request->has('lang')) {
            $response->headers->set('Vary', 'Accept-Language', false);
        }

        return $response;
    }
}
