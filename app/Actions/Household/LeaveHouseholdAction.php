<?php

namespace App\Actions\Household;

use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Models\Household;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('leave', 'household')]
class LeaveHouseholdAction
{
    use AsAction;

    /**
     * The member's claims and assignments are released, their rewards deleted and the admins asked about their tasks, see HouseholdUserObserver.
     */
    public function handle(User $user, Household $household): bool
    {
        $has_left = DB::transaction(fn (): bool => (bool) $user->households()->detach($household->id));
        RecalculateWeeklyPointGoalsAction::run($household);

        return $has_left;
    }

    /**
     * @return array{message: string}
     */
    public function asController(#[CurrentUser] User $user, Household $household): array
    {
        $this->handle($user, $household);

        return ['message' => __('app.success_action')];
    }
}
