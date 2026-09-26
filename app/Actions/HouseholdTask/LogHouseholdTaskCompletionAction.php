<?php

namespace App\Actions\HouseholdTask;

use App\Actions\CompleteTaskInstanceAction;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class LogHouseholdTaskCompletionAction
{
    use AsAction;

    public const int RELOG_COOLDOWN_MINUTES = 5;

    /**
     * Logs a non-recurring task as already done by the user: a new task instance is created
     * (or the given open one is used), claimed by the user and completed right away.
     * The same user cannot log the same task again within a few minutes, so a double tap does not count twice.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, Task $task, ?TaskInstance $task_instance = null): PointTransaction
    {
        if ($task->is_recurring) {
            throw new AuthorizationException(__('app.task_not_loggable'));
        }

        $is_just_logged = TaskInstanceUser::query()
            ->where('user_id', $user->id)
            ->where('completed_at', '>=', now()->subMinutes(self::RELOG_COOLDOWN_MINUTES))
            ->whereHas('taskInstance', fn ($query) => $query->where('task_id', $task->id))
            ->exists();
        if ($is_just_logged) {
            throw new AuthorizationException(__('app.task_just_logged'));
        }

        return DB::transaction(function () use ($user, $household, $task, $task_instance): PointTransaction {
            $task_instance ??= $task->taskInstances()->create([
                'household_id' => $household->id,
                'status' => TaskInstanceStatusEnum::PENDING,
            ]);
            $task_instance->taskInstanceUsers()->create(['user_id' => $user->id]);

            return CompleteTaskInstanceAction::make()->handle($user, $household, $task_instance);
        });
    }

    /**
     * @return array{points: int, points_balance: int, message: string}
     */
    public function asController(#[CurrentUser] User $user, Household $household, Task $task): array
    {
        return self::pointsResponse($this->handle($user, $household, $task));
    }

    /**
     * The response of the endpoints that log a task as done.
     *
     * @return array{points: int, points_balance: int, message: string}
     */
    public static function pointsResponse(PointTransaction $point_transaction): array
    {
        return [
            'points' => $point_transaction->amount,
            'points_balance' => $point_transaction->balance_after,
            'message' => __('app.success_action'),
        ];
    }
}
