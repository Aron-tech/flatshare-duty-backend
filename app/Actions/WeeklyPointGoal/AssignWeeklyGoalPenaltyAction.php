<?php

namespace App\Actions\WeeklyPointGoal;

use App\Actions\CalculateTaskPointsAction;
use App\Enums\TaskInstanceStatusEnum;
use App\Enums\TaskUserWeightEnum;
use App\Models\Task;
use App\Models\TaskInstanceUser;
use App\Models\WeeklyPointGoal;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class AssignWeeklyGoalPenaltyAction
{
    use AsAction;

    /**
     * Assigns the user the cheapest task that is worth more points than the goal's shortfall, due by the end of the next week.
     * When no single task is worth enough, the most valuable tasks are assigned until their sum exceeds the shortfall.
     * Penalty tasks do not earn points, see CompleteTaskInstanceAction.
     *
     * @return Collection<int, TaskInstanceUser>
     */
    public function handle(WeeklyPointGoal $goal): Collection
    {
        $tasks = $this->pickTasks($this->taskValues($goal), (int) $goal->shortfall_points);
        $due_at = $goal->week_starts_at->startOfDay()->addWeeks(2);

        return $tasks->map(function (Task $task) use ($goal, $due_at) {
            $task_instance = $task->taskInstances()
                ->where('status', TaskInstanceStatusEnum::PENDING)
                ->whereNull('completed_at')
                ->whereDoesntHave('taskInstanceUsers')
                ->first()
                ?? $task->taskInstances()->create([
                    'household_id' => $task->household_id,
                    'status' => TaskInstanceStatusEnum::PENDING,
                    'due_at' => $due_at,
                ]);

            if (! $task_instance->due_at) {
                $task_instance->update(['due_at' => $due_at]);
            }

            return $task_instance->taskInstanceUsers()->create([
                'user_id' => $goal->user_id,
                'weekly_point_goal_id' => $goal->id,
            ]);
        })->values();
    }

    /**
     * The points the user would get for each household task they do not have an open claim on.
     *
     * @return Collection<int, array{task: Task, points: int}>
     */
    private function taskValues(WeeklyPointGoal $goal): Collection
    {
        $calculate_task_points = CalculateTaskPointsAction::make();

        return Task::query()
            ->where('household_id', $goal->household_id)
            ->whereDoesntHave('taskInstances', fn ($query) => $query
                ->where('status', TaskInstanceStatusEnum::PENDING)
                ->whereHas('taskInstanceUsers', fn ($query) => $query
                    ->where('user_id', $goal->user_id)
                    ->whereNull('completed_at')))
            ->with(['userWeights' => fn ($query) => $query->where('user_id', $goal->user_id)])
            ->get()
            ->map(fn (Task $task) => [
                'task' => $task,
                'points' => $calculate_task_points->calculate(
                    $task->base_points,
                    $task->userWeights->first()?->weight ?? TaskUserWeightEnum::NEUTRAL,
                ),
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
