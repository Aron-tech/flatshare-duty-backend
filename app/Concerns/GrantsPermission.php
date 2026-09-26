<?php

namespace App\Concerns;

use Illuminate\Auth\Access\Response;

/**
 * Policy helper: a denial carries a translated message, so the client can show why the action is not allowed.
 * The message is translated when the policy runs, after the request locale is set, see SetAppLocaleMiddleware.
 */
trait GrantsPermission
{
    protected function allowIf(bool $condition, string $message_key = 'app.no_permission'): Response
    {
        return $condition ? Response::allow() : Response::deny(__($message_key));
    }
}
