<?php

namespace App\Actions\PushToken;

use App\Http\Requests\StorePushTokenRequest;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class StorePushTokenAction
{
    use AsAction;

    /**
     * Egy token egyszerre csak egy felhasználóhoz tartozhat (eszközváltás/újrabejelentkezés esetén átkerül).
     */
    public function handle(User $user, array $data): PushToken
    {
        return DB::transaction(fn () => PushToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $user->id, 'platform' => $data['platform']],
        ));
    }

    public function asController(StorePushTokenRequest $request): JsonResponse
    {
        try {
            $this->handle($request->user(), $request->validated());

            return response()->json(['message' => __('app.success_action')]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
