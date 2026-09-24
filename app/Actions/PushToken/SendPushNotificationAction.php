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
     * Push értesítést ad sorba a megadott felhasználók összes eszközére.
     *
     * @param  Collection<int, int>|array<int, int>  $userIds
     * @param  array<string, mixed>  $data  kliensoldali payload (pl. útvonal a navigáláshoz)
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
