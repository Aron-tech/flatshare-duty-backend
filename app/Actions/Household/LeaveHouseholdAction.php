<?php

namespace App\Actions\Household;

use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Models\Household;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class LeaveHouseholdAction
{
    use AsAction;

    public function handle(User $user, Household $household): bool
    {
        $has_left = DB::transaction(fn () => (bool) $user->households()->detach($household->id));
        RecalculateWeeklyPointGoalsAction::run($household);

        return $has_left;
    }

    public function asController(Request $request, Household $household): JsonResponse
    {
        try {
            if (! $this->handle($request->user(), $household)) {
                return response()->json(['message' => __('app.failed_action')], 500);
            }

            return response()->json(['message' => __('app.success_action')]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
