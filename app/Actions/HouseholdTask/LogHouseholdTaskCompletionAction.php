<?php

namespace App\Actions\HouseholdTask;

use App\Actions\CompleteTaskInstanceAction;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class LogHouseholdTaskCompletionAction
{
    use AsAction;

    /**
     * Logs a non-recurring task as already done by the user: a new task instance is created
     * (or the given open one is used), claimed by the user and completed right away.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, Task $task, ?TaskInstance $task_instance = null): PointTransaction
    {
        if ($task->household_id !== $household->id || ! $user->households()->whereKey($household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        if ($task->is_recurring) {
            throw new AuthorizationException(__('app.task_not_loggable'));
        }

        return DB::transaction(function () use ($user, $household, $task, $task_instance) {
            $task_instance ??= $task->taskInstances()->create([
                'household_id' => $household->id,
                'status' => TaskInstanceStatusEnum::PENDING,
            ]);
            $task_instance->taskInstanceUsers()->create(['user_id' => $user->id]);

            return CompleteTaskInstanceAction::make()->handle($user, $household, $task_instance);
        });
    }

    public function asController(Request $request, Household $household, Task $task): JsonResponse
    {
        try {
            $point_transaction = $this->handle($request->user(), $household, $task);

            return response()->json([
                'points' => $point_transaction->amount,
                'points_balance' => $point_transaction->balance_after,
                'message' => __('app.success_action'),
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
