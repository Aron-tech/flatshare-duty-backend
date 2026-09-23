<?php

namespace App\Actions\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteHouseholdAction
{
    use AsAction;

    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household): bool
    {
        if ($user->id !== $household->created_by) {
            return false;
        }

        return DB::transaction(fn () => $household->delete());
    }

    public function asController(Request $request, Household $household): JsonResponse
    {
        try {
            if (! $this->handle($request->user(), $household)) {
                return response()->json(['message' => __('app.no_permission')], 403);
            }

            return response()->json(['message' => __('app.success_action')]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
