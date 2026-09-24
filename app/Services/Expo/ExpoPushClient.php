<?php

namespace App\Services\Expo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ExpoPushClient
{
    public const MAX_MESSAGES_PER_REQUEST = 100;

    /**
     * @param  array<int, array<string, mixed>>  $messages  legfeljebb 100 elem
     * @return array<int, array<string, mixed>> az üzenetekkel azonos sorrendű ticketek
     */
    public function send(array $messages): array
    {
        $response = $this->request()
            ->post(config('services.expo.push_url'), $messages)
            ->throw();

        return $response->json('data', []);
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()->asJson()->timeout(15)->retry(2, 500);

        if ($accessToken = config('services.expo.access_token')) {
            $request = $request->withToken($accessToken);
        }

        return $request;
    }
}
