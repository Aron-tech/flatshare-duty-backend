<?php

namespace App\Actions\HouseholdReward;

use App\Actions\CalculateHouseholdMinPointsAction;
use App\Enums\RewardDifficultyEnum;
use App\Http\Requests\CalculateRewardDifficultyRequest;
use App\Models\Household;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\Concerns\AsAction;

class CalculateRewardDifficultyAction
{
    use AsAction;

    /**
     * Tells how hard it is for a member to earn the reward's points in the household.
     *
     * @return array{points_cost: int, difficulty: ?string, difficulty_label: ?string, weekly_points_per_member: int, average_task_points: ?int, weeks_needed: ?float, tasks_needed: ?int}
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, int $points_cost): array
    {
        if (! $user->households()->whereKey($household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        return $this->calculate($household, $points_cost);
    }

    /**
     * @return array{points_cost: int, difficulty: ?string, difficulty_label: ?string, weekly_points_per_member: int, average_task_points: ?int, weeks_needed: ?float, tasks_needed: ?int}
     */
    public function calculate(Household $household, int $points_cost): array
    {
        return $this->evaluate($this->householdAverages($household), $points_cost);
    }

    /**
     * The household's point averages the difficulty is based on, calculated once so many rewards can be evaluated with them.
     * The weekly points come from the recurring tasks, the average task points from every task, both use the average member weight.
     *
     * @return array{weekly_points_per_member: int, average_task_points: ?float}
     */
    public function householdAverages(Household $household): array
    {
        $member_ids = $household->householdUsers()->pluck('user_id');

        $average_task_points = $household->tasks()
            ->with(['userWeights' => fn ($query) => $query->whereIn('user_id', $member_ids)])
            ->get()
            ->avg(fn (Task $task) => $task->base_points * ($task->userWeights->avg(fn ($user_weight) => $user_weight->weight->multiplier()) ?? 1.0));

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

    public function asController(CalculateRewardDifficultyRequest $request, Household $household): JsonResponse
    {
        try {
            return response()->json($this->handle($request->user(), $household, $request->integer('points_cost')));
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
