<?php

namespace App\Actions\HouseholdTask;

use App\Actions\CalculateTaskPointsAction;
use App\Enums\TaskUserWeightEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

class ListOneOffHouseholdTasksAction
{
    use AsAction;

    /**
     * Lists the non-recurring tasks of the household, which any member can log as done.
     * The points fall back to the neutral weight when the user has not weighted the task yet.
     *
     * @return Collection<int, Task>
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household): Collection
    {
        if (! $user->households()->whereKey($household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        $tasks = $household->tasks()
            ->where('is_recurring', false)
            ->with([
                'category',
                'userWeights' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->orderBy('name')
            ->get();

        $calculate_task_points = CalculateTaskPointsAction::make();

        return $tasks->each(fn (Task $task) => $task->setAttribute(
            'points',
            $calculate_task_points->handle($task, $user)
                ?? $calculate_task_points->calculate($task->base_points, TaskUserWeightEnum::NEUTRAL, $task->getData('frequency', 0)),
        ));
    }

    public function asController(Request $request, Household $household): JsonResponse
    {
        try {
            return response()->json(['tasks' => $this->handle($request->user(), $household)]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
