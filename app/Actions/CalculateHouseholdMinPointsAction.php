<?php

namespace App\Actions;

use App\Enums\RecurrenceUnitEnum;
use App\Models\Household;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class CalculateHouseholdMinPointsAction
{
    use AsAction;

    private const HOURS_PER_WEEK = 168;

    private const DAYS_PER_WEEK = 7;

    private const DAYS_PER_MONTH = 30.4375;

    private const DAYS_PER_YEAR = 365.25;

    /**
     * Calculates the points every member has to earn in a week so the household's chores get done.
     * Only the base points and the average member weight are used, the frequency, bounty and solo bonuses are ignored.
     * The weekly point pool of all the tasks is split evenly between the members.
     * With a given week, a recurring task added during that week only counts for the remaining part of the week.
     * Instant (non-recurring) tasks count in full in every week they were open in, see instantPoints().
     */
    public function handle(Household $household, ?CarbonImmutable $week_starts_at = null): int
    {
        $member_ids = $household->householdUsers()->pluck('user_id');

        if ($member_ids->isEmpty()) {
            return 0;
        }

        $tasks = $household->tasks()
            ->where('is_recurring', true)
            ->with(['userWeights' => fn ($query) => $query->whereIn('user_id', $member_ids)])
            ->get();

        $weekly_points = $tasks->sum(fn (Task $task) => $this->weeklyPoints($task) * $this->activeFraction($task->created_at, $week_starts_at));
        $weekly_points += $this->instantPoints($household, $member_ids, $week_starts_at ?? CarbonImmutable::now()->startOfWeek());

        return (int) round($weekly_points / $member_ids->count());
    }

    /**
     * The points of the instant (non-recurring) tasks that were open during the week: created before the week ended
     * and not completed before the week started. An unfinished task therefore carries over to the next week.
     *
     * @param  Collection<int, int>  $member_ids
     */
    private function instantPoints(Household $household, Collection $member_ids, CarbonImmutable $week_starts_at): float
    {
        $week_ends_at = $week_starts_at->addWeek();

        return $household->tasks()
            ->withTrashed()
            ->where('is_recurring', false)
            ->with([
                'userWeights' => fn ($query) => $query->whereIn('user_id', $member_ids),
                'taskInstances' => fn ($query) => $query
                    ->where('created_at', '<', $week_ends_at)
                    ->where(fn ($query) => $query->whereNull('completed_at')->orWhere('completed_at', '>=', $week_starts_at)),
            ])
            ->get()
            ->sum(fn (Task $task) => $this->taskPoints($task) * $task->taskInstances->count());
    }

    /**
     * The part of the week (0-1) that is left after the given moment, 1 when no week is given.
     */
    public function activeFraction(?\DateTimeInterface $since, ?CarbonImmutable $week_starts_at): float
    {
        if (! $since || ! $week_starts_at) {
            return 1.0;
        }

        $week_ends_at = $week_starts_at->addWeek();
        $active_from = CarbonImmutable::instance($since)->max($week_starts_at);
        if ($active_from->greaterThanOrEqualTo($week_ends_at)) {
            return 0.0;
        }

        return $active_from->diffInSeconds($week_ends_at) / $week_starts_at->diffInSeconds($week_ends_at);
    }

    private function weeklyPoints(Task $task): float
    {
        return $this->taskPoints($task) * $this->occurrencesPerWeek($task);
    }

    /**
     * The points of one occurrence, every allowed assignee included.
     */
    private function taskPoints(Task $task): float
    {
        $average_multiplier = $task->userWeights->avg(fn ($user_weight) => $user_weight->weight->multiplier()) ?? 1.0;

        return $task->base_points * $average_multiplier * $task->max_user;
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
