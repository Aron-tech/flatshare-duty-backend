<?php

namespace App\Actions;

use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class GetHouseholdUserAction
{
    use AsAction;

    /**
     * @throws AuthorizationException when the user is not a member of the household
     */
    public function handle(User $user, Household $household): HouseholdUser
    {
        return $user->membershipOf($household) ?? throw new AuthorizationException(__('app.no_permission'));
    }

    /**
     * @return array{household_user: HouseholdUser, min_points: int, weekly_points: int, spendable_points: int, grace_days_left: int}
     */
    public function asController(#[CurrentUser] User $user, Household $household): array
    {
        $household_user = $this->handle($user, $household);
        $goal = RecalculateWeeklyPointGoalsAction::make()->currentGoals($household)->get($household_user->user_id);

        return [
            'household_user' => $household_user,
            'min_points' => $goal->target_points ?? 0,
            'weekly_points' => $household_user->weeklyPoints(),
            'spendable_points' => $household_user->spendablePoints(),
            'grace_days_left' => $household_user->graceDaysLeft(),
        ];
    }
}
