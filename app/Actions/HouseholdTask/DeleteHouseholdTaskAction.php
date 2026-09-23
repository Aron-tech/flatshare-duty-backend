<?php

namespace App\Actions\HouseholdTask;

use App\Enums\RoleEnum;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteHouseholdTaskAction
{
    use AsAction;

    public function handle(User $user, Household $household, Task $task): bool
    {
        $household_user = HouseholdUser::where('household_id', $household->id)->where('user_id', $user->id)->first();
        if (!$household_user || $household_user->role === RoleEnum::CHILD) {
            return false;
        }

        return DB::transaction(fn () => $task->delete());
    }

    public function asController(Request $request, Household $household, Task $task): JsonResponse
    {
        try {
            if (! $this->handle($request->user(), $household, $task)) {
                return response()->json(['message' => __('app.no_permission')], 403);
            }

            return response()->json(['message' => __('app.success_action')]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
