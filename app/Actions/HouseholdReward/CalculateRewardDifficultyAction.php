<?php

namespace App\Actions\HouseholdReward;

use App\Actions\CalculateHouseholdMinPointsAction;
use App\Actions\CalculateTaskPointsAction;
use App\Enums\RewardDifficultyEnum;
use App\Http\Requests\CalculateRewardDifficultyRequest;
use App\Models\Household;
use App\Models\Task;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class CalculateRewardDifficultyAction
{
    use AsAction;

    /**
     * Tells how hard it is for a member to earn the reward's points in the household.
     *
     * @return array{points_cost: int, difficulty: ?string, difficulty_label: ?string, weekly_points_per_member: int, average_task_points: ?int, weeks_needed: ?float, tasks_needed: ?int}
     */
    public function handle(Household $household, int $points_cost): array
    {
        return $this->evaluate($this->householdAverages($household), $points_cost);
    }

    /**
     * The household's point averages the difficulty is based on, calculated once so many rewards can be evaluated with them.
     * The weekly points come from the recurring tasks, the average task points from every task, both use the common weight of the members.
     *
     * @return array{weekly_points_per_member: int, average_task_points: ?float}
     */
    public function householdAverages(Household $household): array
    {
        $member_ids = $household->householdUsers()->pluck('user_id');

        $calculate_task_points = CalculateTaskPointsAction::make();
        $average_task_points = $household->tasks()
            ->with(['userWeights' => fn ($query) => $query->whereIn('user_id', $member_ids)])
            ->get()
            ->avg(fn (Task $task): float => $task->base_points * $calculate_task_points->weightMultiplier($task, $member_ids));

        return [
            'weekly_points_per_member' => CalculateHouseholdMinPointsAction::run($household),
            'average_task_points' => $average_task_points ? (float) $average_task_points : null,
        ];
    }

    /**
     * The weeks needed decide the difficulty, the average tasks needed are only used when the household has no recurring tasks.
     *
     * @param  array{weekly_points_per_member: int, average_task_points: ?float}  $averages
     * @return array{points_cost: int, difficulty: ?string, difficulty_label: ?string, weekly_points_per_member: int, average_task_points: ?int, weeks_needed: ?float, tasks_needed: ?int}
     */
    public function evaluate(array $averages, int $points_cost): array
    {
        $weekly_points = $averages['weekly_points_per_member'];
        $average_task_points = $averages['average_task_points'];

        $weeks_needed = $weekly_points > 0 ? $points_cost / $weekly_points : null;
        $tasks_needed = $average_task_points ? (int) ceil($points_cost / $average_task_points) : null;

        $difficulty = match (true) {
            $weeks_needed !== null => RewardDifficultyEnum::fromWeeks($weeks_needed),
            $tasks_needed !== null => RewardDifficultyEnum::fromTasks($tasks_needed),
            default => null,
        };

        return [
            'points_cost' => $points_cost,
            'difficulty' => $difficulty?->value,
            'difficulty_label' => $difficulty?->getName(),
            'weekly_points_per_member' => $weekly_points,
            'average_task_points' => $average_task_points ? (int) round($average_task_points) : null,
            'weeks_needed' => $weeks_needed !== null ? round($weeks_needed, 1) : null,
            'tasks_needed' => $tasks_needed,
        ];
    }

    /**
     * @return array{points_cost: int, difficulty: ?string, difficulty_label: ?string, weekly_points_per_member: int, average_task_points: ?int, weeks_needed: ?float, tasks_needed: ?int}
     */
    public function asController(CalculateRewardDifficultyRequest $request, Household $household): array
    {
        return $this->handle($household, $request->integer('points_cost'));
    }
}
