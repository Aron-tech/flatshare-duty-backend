<?php

namespace App\Http\Middleware;

use App\Enums\LanguageEnum;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetAppLocaleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only supported languages are accepted, a raw header like "en-US,en;q=0.9" would break the translations.
        $language = $request->user()?->language ?? LanguageEnum::fromLocale((string) $request->header('Accept-Language'));
        if ($language) {
            app()->setLocale($language->value);
        }

        return $next($request);
    }
}
