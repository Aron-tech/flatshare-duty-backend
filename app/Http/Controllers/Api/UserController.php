<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * @return array{user: User}
     */
    public function me(#[CurrentUser] User $user): array
    {
        return ['user' => $user];
    }

    /**
     * @return array{user: User, message: string}
     */
    public function update(UpdateUserRequest $request, #[CurrentUser] User $user): array
    {
        DB::transaction(fn (): bool => $user->update($request->validated()));

        // A nyelvváltás után a válasz már az új nyelven jöjjön.
        app()->setLocale($user->language->value);

        return ['user' => $user, 'message' => __('app.success_action')];
    }
}
