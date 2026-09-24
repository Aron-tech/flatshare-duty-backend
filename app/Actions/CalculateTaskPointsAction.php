<?php

namespace App\Actions;

use App\Models\TaskInstance;
use App\Models\TaskUserWeight;
use Carbon\CarbonInterface;
use Lorisleiva\Actions\Concerns\AsAction;

class CalculateTaskPointsAction
{
    use AsAction;

    /**
     * Uses the task's eager loaded userWeights relation when available,
     * so listing many tasks does not run a query per task.
     */
    public function handle(TaskUserWeight $task_user_weight, ?TaskInstance $task_instance = null, bool $is_solo = false): ?int
    {
        return $this->calculate($task_user_weight->task->base_points, $task_user_weight, $task_instance?->due_at, $is_solo);
    }

    public function calculate(int $base_points, TaskUserWeight $task_user_weight, ?CarbonInterface $due_at = null, bool $is_solo = false): int
    {
        $weight_multiplier = $task_user_weight->weight->multiplier();
        $frequency_multiplier = $this->getFrequencyMultiplier($task_user_weight->task->getData('frequency', 0));
        $bounty_multiplier = $this->getBountyMultiplier($due_at);

        $combined_multiplier = $weight_multiplier * $frequency_multiplier * $bounty_multiplier;

        $clamped_multiplier = max(0.50, min($combined_multiplier, 1.60));

        $points = $base_points * $clamped_multiplier;

        if ($is_solo) {
            $points *= 1.5;
        }

        return (int) round($points);
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
