<?php

namespace App\Actions\PushToken;

use App\Http\Requests\DeletePushTokenRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\Concerns\AsAction;

class DeletePushTokenAction
{
    use AsAction;

    public function handle(User $user, string $token): void
    {
        $user->pushTokens()->where('token', $token)->delete();
    }

    public function asController(DeletePushTokenRequest $request): JsonResponse
    {
        try {
            $this->handle($request->user(), $request->validated('token'));

            return response()->json(['message' => __('app.success_action')]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
