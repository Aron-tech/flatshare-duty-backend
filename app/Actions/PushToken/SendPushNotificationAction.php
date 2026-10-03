<?php

namespace App\Actions\PushToken;

use App\Jobs\SendExpoPushNotificationsJob;
use App\Models\PushToken;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class SendPushNotificationAction
{
    use AsAction;

    /**
     * @param  Collection<int, int>|array<int, int>  $userIds
     * @param  array<string, mixed>  $data  client-side payload, e.g. a route to navigate to
     */
    public function handle(Collection|array $userIds, string $title, string $body, array $data = []): void
    {
        $tokens = PushToken::whereIn('user_id', $userIds)->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        SendExpoPushNotificationsJob::dispatch($tokens, $title, $body, $data);
    }
}
