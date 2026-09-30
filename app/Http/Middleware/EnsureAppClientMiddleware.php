<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only our mobile app may call the API: it sends one of the configured keys in the X-App-Key header.
 * Several keys can be valid at once, so a key can be rotated without breaking the builds already in the stores.
 * Without a configured key the check is skipped outside production, in production every request is refused.
 */
class EnsureAppClientMiddleware
{
    public const string HEADER = 'X-App-Key';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $keys = array_filter(config('app.client_keys'));

        if ($keys === [] && ! app()->isProduction()) {
            return $next($request);
        }

        $sent_key = (string) $request->header(self::HEADER);
        $is_known_client = $sent_key !== '' && array_any($keys, fn (string $key): bool => hash_equals($key, $sent_key));

        abort_unless($is_known_client, Response::HTTP_FORBIDDEN, __('app.unknown_client'));

        return $next($request);
    }
}
