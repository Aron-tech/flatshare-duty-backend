<?php

namespace App\Actions;

use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

class GetHouseholdUserAction
{
    use AsAction;

    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household): HouseholdUser
    {
        $household_user = $household->householdUsers()->where('user_id', $user->id)->first();
        if (! $household_user) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        return $household_user;
    }

    public function asController(Request $request, Household $household): JsonResponse
    {
        try {
            $household_user = $this->handle($request->user(), $household);

            $goal = RecalculateWeeklyPointGoalsAction::make()->currentGoals($household)->get($household_user->user_id);

            return response()->json(['household_user' => $household_user, 'min_points' => $goal->target_points ?? 0]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
