<?php

namespace App\Actions;

use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\TaskInstance;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

class ListTaskInstancesAction
{
    use AsAction;

    /**
     * Splits the open task instances of the household into the ones the user
     * can still claim and the ones the user has already claimed.
     * Instances whose part the user has already completed are left out of both lists.
     * Penalty tasks assigned to the user are flagged with is_penalty and earn no points.
     *
     * @return array{available: list<TaskInstance>, claimed: list<TaskInstance>}
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household): array
    {
        if (! $user->households()->whereKey($household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        $task_instances = $household->taskInstances()
            ->where('status', TaskInstanceStatusEnum::PENDING)
            ->whereNull('completed_at')
            ->with([
                'task.category',
                'task.userWeights' => fn ($query) => $query->where('user_id', $user->id),
                'taskInstanceUsers',
            ])
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $calculate_task_points = CalculateTaskPointsAction::make();

        $task_instances->each(function (TaskInstance $task_instance) use ($calculate_task_points, $user) {
            $is_penalty = (bool) $task_instance->taskInstanceUsers->firstWhere('user_id', $user->id)?->weekly_point_goal_id;
            $task_instance->setAttribute('is_penalty', $is_penalty);
            $task_instance->setAttribute('points', $is_penalty ? 0 : $calculate_task_points->handle($task_instance, $user));
        });

        [$claimed, $others] = $task_instances->partition(
            fn (TaskInstance $task_instance) => $task_instance->taskInstanceUsers->contains('user_id', $user->id)
        );

        $claimed = $claimed->reject(
            fn (TaskInstance $task_instance) => $task_instance->taskInstanceUsers->firstWhere('user_id', $user->id)->completed_at
        );

        $available = $others->filter(
            fn (TaskInstance $task_instance) => $task_instance->taskInstanceUsers->count() < $task_instance->task->max_user
        );

        return [
            'available' => $available->values()->all(),
            'claimed' => $claimed->values()->all(),
        ];
    }

    public function asController(Request $request, Household $household): JsonResponse
    {
        try {
            return response()->json($this->handle($request->user(), $household));
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
