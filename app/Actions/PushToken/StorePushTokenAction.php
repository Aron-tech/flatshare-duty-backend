<?php

namespace App\Actions\PushToken;

use App\Http\Requests\StorePushTokenRequest;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class StorePushTokenAction
{
    use AsAction;

    /**
     * Egy token egyszerre csak egy felhasználóhoz tartozhat (eszközváltás/újrabejelentkezés esetén átkerül).
     *
     * @param  array{token: string, platform: string}  $data
     */
    public function handle(User $user, array $data): PushToken
    {
        return DB::transaction(fn (): PushToken => PushToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $user->id, 'platform' => $data['platform']],
        ));
    }

    /**
     * @return array{message: string}
     */
    public function asController(StorePushTokenRequest $request, #[CurrentUser] User $user): array
    {
        $this->handle($user, $request->validated());

        return ['message' => __('app.success_action')];
    }
}
