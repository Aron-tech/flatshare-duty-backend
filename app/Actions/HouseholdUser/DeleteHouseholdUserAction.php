<?php

namespace App\Actions\HouseholdUser;

use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Models\HouseholdUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * A member can remove themselves, anyone else can only be removed by an admin of the household, see HouseholdUserPolicy.
 */
#[Authorize('delete', 'household_user')]
class DeleteHouseholdUserAction
{
    use AsAction;

    /**
     * The member's claims and assignments are released, their rewards deleted and the admins asked about their tasks, see HouseholdUserObserver.
     */
    public function handle(HouseholdUser $household_user): bool
    {
        $is_deleted = DB::transaction(fn (): bool => (bool) $household_user->delete());
        RecalculateWeeklyPointGoalsAction::run($household_user->household);

        return $is_deleted;
    }

    /**
     * @return array{message: string}
     */
    public function asController(HouseholdUser $household_user): array
    {
        $this->handle($household_user);

        return ['message' => __('app.success_action')];
    }
}
