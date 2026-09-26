<?php

namespace App\Actions;

use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class RequestTaskInstanceGraceDayAction
{
    use AsAction;

    /**
     * Moves the due date of the task instance the user has claimed by TaskInstanceUser::GRACE_HOURS, without approval.
     * A member has TaskInstanceUser::GRACE_DAYS_PER_PERIOD grace days in each goal period of the household,
     * and can use one on a claim once, before its due date. Until the new due date the claim is not released
     * (ReleaseOverdueTaskClaimsAction) and nobody can take the task over for the overdue bounty.
     * The co-claimers of a shared task get the extra time too, the due date belongs to the task instance.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, TaskInstance $task_instance): TaskInstance
    {
        $household_user = GetHouseholdUserAction::run($user, $household);

        return DB::transaction(function () use ($household_user, $task_instance) {
            HouseholdUser::query()->lockForUpdate()->findOrFail($household_user->id);
            $task_instance = TaskInstance::query()->lockForUpdate()->findOrFail($task_instance->id);
            $claim = $task_instance->taskInstanceUsers()->where('user_id', $household_user->user_id)->first();

            if (! $task_instance->isOpen() || ! $claim || $claim->completed_at || $claim->grace_granted_at || ! $task_instance->due_at?->isFuture()) {
                throw new AuthorizationException(__('app.grace_day_not_allowed'));
            }

            if ($household_user->graceDaysLeft() <= 0) {
                throw new AuthorizationException(__('app.grace_day_used'));
            }

            $claim->update(['grace_granted_at' => now()]);
            $task_instance->update(['due_at' => $task_instance->due_at->addHours(TaskInstanceUser::GRACE_HOURS)]);

            return $task_instance;
        });
    }

    /**
     * @return array{due_at: mixed, grace_days_left: int, message: string}
     */
    public function asController(#[CurrentUser] User $user, Household $household, TaskInstance $task_instance): array
    {
        $task_instance = $this->handle($user, $household, $task_instance);

        return [
            'due_at' => $task_instance->due_at,
            'grace_days_left' => GetHouseholdUserAction::run($user, $household)->graceDaysLeft(),
            'message' => __('app.success_action'),
        ];
    }
}
