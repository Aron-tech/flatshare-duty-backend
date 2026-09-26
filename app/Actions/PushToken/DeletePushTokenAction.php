<?php

namespace App\Actions\PushToken;

use App\Http\Requests\DeletePushTokenRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Lorisleiva\Actions\Concerns\AsAction;

class DeletePushTokenAction
{
    use AsAction;

    /**
     * Only the user's own token is deleted, e.g. at logout.
     */
    public function handle(User $user, string $token): void
    {
        $user->pushTokens()->where('token', $token)->delete();
    }

    /**
     * @return array{message: string}
     */
    public function asController(DeletePushTokenRequest $request, #[CurrentUser] User $user): array
    {
        $this->handle($user, $request->validated('token'));

        return ['message' => __('app.success_action')];
    }
}
