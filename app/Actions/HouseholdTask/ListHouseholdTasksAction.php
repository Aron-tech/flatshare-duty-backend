<?php

namespace App\Actions\HouseholdTask;

use App\Actions\CalculateTaskPointsAction;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskUserWeight;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class ListHouseholdTasksAction
{
    use AsAction;

    /**
     * Lists the tasks of the household for any member, with the user's own weight and the rotation members of each task,
     * grouped by the category name. The points are the household's common price of the task for one claimer, see CalculateTaskPointsAction.
     *
     * @return Collection<string, Collection<int, Task>>
     */
    public function handle(User $user, Household $household): Collection
    {
        $tasks = $household->tasks()
            ->with([
                'category',
                'rotations',
                'userWeights' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->orderBy('name')
            ->get();

        $member_ids = $household->householdUsers()->pluck('user_id');
        $member_weights = TaskUserWeight::query()
            ->whereIn('task_id', $tasks->modelKeys())
            ->whereIn('user_id', $member_ids)
            ->get()
            ->groupBy('task_id');

        $calculate_task_points = CalculateTaskPointsAction::make();
        $tasks->each(fn (Task $task): Task => $task->setAttribute('points', $calculate_task_points->handle(
            (clone $task)->setRelation('userWeights', $member_weights->get($task->id, collect())),
            $member_ids,
        )));

        return $tasks->groupBy(fn (Task $task): string => $task->category?->name ?? __('app.other'));
    }

    /**
     * @return array{household: Household, tasks: Collection<string, Collection<int, Task>>}
     */
    public function asController(#[CurrentUser] User $user, Household $household): array
    {
        return ['household' => $household, 'tasks' => $this->handle($user, $household)];
    }
}
