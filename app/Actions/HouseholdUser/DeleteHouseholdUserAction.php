<?php

namespace App\Actions\HouseholdUser;

use App\Models\HouseholdUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteHouseholdUserAction
{
    use AsAction;

    public function handle(User $user, HouseholdUser $household_user): bool
    {
        $is_removing_creator = $household_user->user_id === $household_user->household->created_by;
        $is_self = $household_user->user_id === $user->id;

        if ($is_removing_creator && ! $is_self && ! $user->isAdminOf($household_user->household_id)) {
            return false;
        }

        return DB::transaction(fn () => $household_user->delete());
    }

    public function asController(Request $request, HouseholdUser $household_user): JsonResponse
    {
        try {
            if (! $this->handle($request->user(), $household_user)) {
                return response()->json(['message' => __('app.no_permission')], 403);
            }

            return response()->json(['message' => __('app.success_action')]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
