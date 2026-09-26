<?php

namespace App\Actions\WeeklyPointGoal;

use App\Actions\CalculateTaskPointsAction;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\HouseholdUser;
use App\Models\Task;
use App\Models\TaskInstanceUser;
use App\Models\WeeklyPointGoal;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class AssignWeeklyGoalPenaltyAction
{
    use AsAction;

    /**
     * Assigns the user the cheapest task that is worth more points than the penalty points, due by the end of the next goal period.
     * When no single task is worth enough, the most valuable tasks are assigned until their sum exceeds the penalty points.
     * Each claim covers a part of the penalty points, and only the points above it are paid, see TaskInstanceUser::payablePoints().
     *
     * @param  int  $penalty_points  the shortfall to cover, see WeeklyPointGoal::penaltyPoints()
     * @return Collection<int, TaskInstanceUser>
     */
    public function handle(WeeklyPointGoal $goal, int $penalty_points): Collection
    {
        $task_values = $this->taskValues($goal);
        $points_by_task = $task_values->mapWithKeys(fn (array $task_value) => [$task_value['task']->id => $task_value['points']]);
        $tasks = $this->pickTasks($task_values, $penalty_points);
        $due_at = WeeklyPointGoal::weekEndsAt($goal->endsAt(), $goal->household);
        $uncovered_points = $penalty_points;

        return $tasks->map(function (Task $task) use ($goal, $due_at, $points_by_task, &$uncovered_points) {
            $covered_points = min($points_by_task[$task->id], $uncovered_points);
            $uncovered_points -= $covered_points;

            $task_instance = $task->taskInstances()
                ->open()
                ->whereDoesntHave('taskInstanceUsers')
                ->first()
                ?? $task->taskInstances()->create([
                    'household_id' => $task->household_id,
                    'status' => TaskInstanceStatusEnum::PENDING,
                    'due_at' => $due_at,
                ]);

            if (! $task_instance->due_at || $task_instance->due_at->lessThan($due_at)) {
                $task_instance->update(['due_at' => $due_at]);
            }

            return $task_instance->claimFor($goal->user_id, ['weekly_point_goal_id' => $goal->id, 'penalty_points' => $covered_points]);
        })->values();
    }

    /**
     * The points of each household task the user does not have an open claim on.
     *
     * @return Collection<int, array{task: Task, points: int}>
     */
    private function taskValues(WeeklyPointGoal $goal): Collection
    {
        $calculate_task_points = CalculateTaskPointsAction::make();
        $member_ids = HouseholdUser::query()->where('household_id', $goal->household_id)->pluck('user_id');

        return Task::query()
            ->where('household_id', $goal->household_id)
            ->whereDoesntHave('taskInstances', fn ($query) => $query
                ->where('status', TaskInstanceStatusEnum::PENDING)
                ->whereHas('taskInstanceUsers', fn ($query) => $query
                    ->where('user_id', $goal->user_id)
                    ->whereNull('completed_at')))
            ->with(['userWeights' => fn ($query) => $query->whereIn('user_id', $member_ids)])
            ->get()
            ->map(fn (Task $task) => [
                'task' => $task,
                'points' => $calculate_task_points->handle($task, $member_ids),
            ]);
    }

    /**
     * @param  Collection<int, array{task: Task, points: int}>  $task_values
     * @return Collection<int, Task>
     */
    public function pickTasks(Collection $task_values, int $shortfall): Collection
    {
        $sorted = $task_values->sortBy([['points', 'asc'], [fn ($a, $b) => $a['task']->id <=> $b['task']->id]])->values();

        $single = $sorted->first(fn (array $task_value) => $task_value['points'] > $shortfall);
        if ($single) {
            return collect([$single['task']]);
        }

        $picked = collect();
        $sum = 0;
        foreach ($sorted->reverse() as $task_value) {
            $picked->push($task_value['task']);
            $sum += $task_value['points'];
            if ($sum > $shortfall) {
                break;
            }
        }

        return $picked;
    }
}
