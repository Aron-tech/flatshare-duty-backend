<?php

namespace App\Actions;

use App\Enums\TaskUserWeightEnum;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskUserWeight;
use App\Models\User;
use Carbon\CarbonInterface;
use Lorisleiva\Actions\Concerns\AsAction;

class CalculateTaskPointsAction
{
    use AsAction;

    /**
     * Calculates the user's points for a task or a task instance.
     * A task instance also adds the overdue bounty based on its due date.
     * Uses the task's eager loaded userWeights relation when available,
     * so listing many tasks does not run a query per task.
     */
    public function handle(Task|TaskInstance $task_or_instance, User $user, bool $is_solo = false): ?int
    {
        $task = $task_or_instance instanceof TaskInstance ? $task_or_instance->task : $task_or_instance;
        $due_at = $task_or_instance instanceof TaskInstance ? $task_or_instance->due_at : null;

        return $this->calculate(
            $task->base_points,
            $this->findUserWeight($task, $user)?->weight,
            $task->getData('frequency', 0),
            $due_at,
            $is_solo,
        );
    }

    public function calculate(int $base_points, ?TaskUserWeightEnum $weight, int $frequency = 0, ?CarbonInterface $due_at = null, bool $is_solo = false): ?int
    {
        if (! $weight) {
            return null;
        }

        $combined_multiplier = $weight->multiplier() * $this->getFrequencyMultiplier($frequency) * $this->getBountyMultiplier($due_at);
        $clamped_multiplier = max(0.50, min($combined_multiplier, 1.60));

        $points = $base_points * $clamped_multiplier;

        if ($is_solo) {
            $points *= 1.5;
        }

        return (int) round($points);
    }

    private function findUserWeight(Task $task, User $user): ?TaskUserWeight
    {
        if ($task->relationLoaded('userWeights')) {
            return $task->userWeights->firstWhere('user_id', $user->id);
        }

        return $task->userWeights()->where('user_id', $user->id)->first();
    }

    private function getFrequencyMultiplier(int $frequency): float
    {
        return match ($frequency) {
            0 => 1.00,
            1 => 0.90,
            2 => 0.80,
            default => 0.70,
        };
    }

    private function getBountyMultiplier(?CarbonInterface $due_at): float
    {
        if (! $due_at || now()->lessThanOrEqualTo($due_at)) {
            return 1.00;
        }

        $days_overdue = (int) $due_at->diffInDays(now());

        return min(1.00 + ($days_overdue * 0.05), 1.30);
    }
}
