<?php

namespace App\Actions;

use App\Enums\PointTransactionType;
use App\Enums\TaskInstanceStatusEnum;
use App\Enums\TaskUserWeightEnum;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\TaskInstance;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class CompleteTaskInstanceAction
{
    use AsAction;

    /**
     * Completes the user's claim on the task instance and credits the earned points.
     * The solo bonus applies when a multi-user task is done by its only claimer.
     * A penalty task assigned for missing the weekly minimum points earns no points.
     * The task instance itself is closed once every claimer has completed their part.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, TaskInstance $task_instance): PointTransaction
    {
        if ($task_instance->household_id !== $household->id) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        $household_user = $household->householdUsers()->where('user_id', $user->id)->first();
        if (! $household_user) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        return DB::transaction(function () use ($user, $household_user, $task_instance) {
            $task_instance = TaskInstance::query()->with('task')->lockForUpdate()->findOrFail($task_instance->id);
            $claims = $task_instance->taskInstanceUsers()->lockForUpdate()->get();
            $claim = $claims->firstWhere('user_id', $user->id);

            $is_open = $task_instance->status === TaskInstanceStatusEnum::PENDING && ! $task_instance->completed_at;
            if (! $is_open || ! $claim || $claim->completed_at) {
                throw new AuthorizationException(__('app.task_instance_not_completable'));
            }

            $claim->update(['completed_at' => now()]);

            if ($claims->every(fn ($task_instance_user) => $task_instance_user->completed_at)) {
                $task_instance->update(['status' => TaskInstanceStatusEnum::ACCEPTED, 'completed_at' => now()]);
            }

            if ($claim->weekly_point_goal_id) {
                return $this->creditPoints($household_user, $task_instance, 0, PointTransactionType::PENALTY_TASK_COMPLETION);
            }

            $is_solo = $task_instance->task->max_user > 1 && $claims->count() === 1;

            return $this->creditPoints($household_user, $task_instance, $this->calculatePoints($task_instance, $user, $is_solo), PointTransactionType::TASK_COMPLETION);
        });
    }

    /**
     * Falls back to the neutral weight when the user has not weighted the task yet.
     */
    private function calculatePoints(TaskInstance $task_instance, User $user, bool $is_solo): int
    {
        $calculate_task_points = CalculateTaskPointsAction::make();

        return $calculate_task_points->handle($task_instance, $user, $is_solo)
            ?? $calculate_task_points->calculate(
                $task_instance->task->base_points,
                TaskUserWeightEnum::NEUTRAL,
                $task_instance->task->getData('frequency', 0),
                $task_instance->due_at,
                $is_solo,
            );
    }

    private function creditPoints(HouseholdUser $household_user, TaskInstance $task_instance, int $points, PointTransactionType $type): PointTransaction
    {
        $household_user = HouseholdUser::query()->lockForUpdate()->findOrFail($household_user->id);
        $household_user->increment('points_balance', $points);

        return PointTransaction::create([
            'household_id' => $household_user->household_id,
            'user_id' => $household_user->user_id,
            'amount' => $points,
            'balance_after' => $household_user->points_balance,
            'type' => $type,
            'task_instance_id' => $task_instance->id,
        ]);
    }

    public function asController(Request $request, Household $household, TaskInstance $task_instance): JsonResponse
    {
        try {
            $point_transaction = $this->handle($request->user(), $household, $task_instance);

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
