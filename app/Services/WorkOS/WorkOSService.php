<?php

namespace App\Services\WorkOS;

use WorkOS\Exception\WorkOSException;
use WorkOS\Resource\AuthenticationResponse;
use WorkOS\UserManagement;
use WorkOS\WorkOS;

class WorkOSService
{
    public function __construct()
    {
        WorkOS::setApiKey(config('services.workos.api_key'));
    }

    /**
     * @throws WorkOSException
     */
    public function authenticateWithCode(string $code): AuthenticationResponse
    {
        return new UserManagement()->authenticateWithCode(
            config('services.workos.client_id'),
            $code
        );
    }
}
