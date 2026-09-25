<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\UpdateUserRequest;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json(['user' => $user, 'message' => __('app.success_action')]);
    }
}
