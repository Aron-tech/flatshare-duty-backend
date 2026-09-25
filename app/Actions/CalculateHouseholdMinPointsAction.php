<?php

namespace App\Actions;

use App\Enums\RecurrenceUnitEnum;
use App\Models\Household;
use App\Models\Task;
use Lorisleiva\Actions\Concerns\AsAction;

class CalculateHouseholdMinPointsAction
{
    use AsAction;

    private const HOURS_PER_WEEK = 168;

    private const DAYS_PER_WEEK = 7;

    private const DAYS_PER_MONTH = 30.4375;

    private const DAYS_PER_YEAR = 365.25;

    /**
     * Calculates the points every member has to earn in a week so the household's recurring tasks get done.
     * Only the base points and the average member weight are used, the frequency, bounty and solo bonuses are ignored.
     * The weekly point pool of all the tasks is split evenly between the members.
     */
    public function handle(Household $household): int
    {
        $member_ids = $household->householdUsers()->pluck('user_id');

        if ($member_ids->isEmpty()) {
            return 0;
        }

        $tasks = $household->tasks()
            ->where('is_recurring', true)
            ->with(['userWeights' => fn ($query) => $query->whereIn('user_id', $member_ids)])
            ->get();

        $weekly_points = $tasks->sum(fn (Task $task) => $this->weeklyPoints($task));

        return (int) round($weekly_points / $member_ids->count());
    }

    private function weeklyPoints(Task $task): float
    {
        $average_multiplier = $task->userWeights->avg(fn ($user_weight) => $user_weight->weight->multiplier()) ?? 1.0;

        return $task->base_points * $average_multiplier * $task->max_user * $this->occurrencesPerWeek($task);
    }

    private function occurrencesPerWeek(Task $task): float
    {
        if (! $task->recurrence_unit || ! $task->recurrence_interval) {
            return 0.0;
        }

        $interval = $task->recurrence_interval;

        return match ($task->recurrence_unit) {
            RecurrenceUnitEnum::HOUR => self::HOURS_PER_WEEK / $interval,
            RecurrenceUnitEnum::DAY => self::DAYS_PER_WEEK / $interval,
            RecurrenceUnitEnum::WEEK => 1 / $interval,
            RecurrenceUnitEnum::MONTH => self::DAYS_PER_WEEK / (self::DAYS_PER_MONTH * $interval),
            RecurrenceUnitEnum::YEAR => self::DAYS_PER_WEEK / (self::DAYS_PER_YEAR * $interval),
        };
    }
}
