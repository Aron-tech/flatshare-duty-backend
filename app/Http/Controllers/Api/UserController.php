<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    public function update(UpdateUserRequest $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(fn () => $user->update($request->validated()));

        // A nyelvváltás után a válasz már az új nyelven jöjjön.
        app()->setLocale($user->language->value);

        return response()->json(['user' => $user, 'message' => __('app.success_action')]);
    }
}
