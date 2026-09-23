<?php

namespace App\Http\Middleware;

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
        if ($request->user() && $request->user()->language->value) {
            app()->setLocale($request->user()->language->value);
        } elseif ($request->header('Accept-Language')) {
            app()->setLocale($request->header('Accept-Language'));
        }

        return $next($request);
    }
}
