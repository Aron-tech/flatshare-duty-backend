<?php

namespace App\Actions\HouseholdTask;

use App\Actions\CalculateTaskPointsAction;
use App\Models\Household;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class ListOneOffHouseholdTasksAction
{
    use AsAction;

    /**
     * Lists the non-recurring tasks of the household, which any member can log as done.
     * The points are the same for every member, see CalculateTaskPointsAction.
     *
     * @return Collection<int, Task>
     */
    public function handle(Household $household): Collection
    {
        $member_ids = $household->householdUsers()->pluck('user_id');
        $calculate_task_points = CalculateTaskPointsAction::make();

        return $household->tasks()
            ->oneOff()
            ->with([
                'category',
                'userWeights' => fn ($query) => $query->whereIn('user_id', $member_ids),
            ])
            ->orderBy('name')
            ->get()
            ->each(fn (Task $task): Task => $task->setAttribute('points', $calculate_task_points->handle($task, $member_ids)));
    }

    /**
     * @return array{tasks: Collection<int, Task>}
     */
    public function asController(Household $household): array
    {
        return ['tasks' => $this->handle($household)];
    }
}
