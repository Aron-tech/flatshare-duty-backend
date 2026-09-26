<?php

namespace App\Actions;

use App\Enums\RecurrenceUnitEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\WeeklyPointGoal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class CalculateHouseholdMinPointsAction
{
    use AsAction;

    private const int HOURS_PER_WEEK = 168;

    private const int DAYS_PER_WEEK = 7;

    private const float DAYS_PER_MONTH = 30.4375;

    private const float DAYS_PER_YEAR = 365.25;

    /**
     * Calculates the points every member has to earn in the household's goal period (a week or a month) so the chores get done.
     * Only the base points and the common weight of the members are used, the overdue bounty is ignored.
     * The weekly point pool of all the tasks, scaled to the length of the period, is split evenly between the members.
     * With a given period, a recurring task added during that period only counts for the remaining part of the period.
     * Instant (non-recurring) tasks count in full in every period they were open in, see instantPoints().
     */
    public function handle(Household $household, ?CarbonImmutable $week_starts_at = null): int
    {
        $member_ids = $household->householdUsers()->pluck('user_id');

        if ($member_ids->isEmpty()) {
            return 0;
        }

        $tasks = $household->tasks()
            ->recurring()
            ->with(['userWeights' => fn ($query) => $query->whereIn('user_id', $member_ids)])
            ->get();

        $period_starts_at = $week_starts_at ?? WeeklyPointGoal::weekStartsAt(null, $household);
        $weeks_in_period = $this->weeksInPeriod($household, $period_starts_at);

        $period_points = $tasks->sum(fn (Task $task): float => $this->weeklyPoints($task, $member_ids) * $weeks_in_period * $this->activeFraction($task->created_at, $week_starts_at, $household));
        $period_points += $this->instantPoints($household, $member_ids, $period_starts_at);

        return (int) round($period_points / $member_ids->count());
    }

    /**
     * The length of the period in weeks, counted in calendar days so a daylight saving change does not matter.
     */
    public function weeksInPeriod(Household $household, CarbonImmutable $week_starts_at): float
    {
        $timezone = config('app.week_timezone');
        $starts_on = $week_starts_at->setTimezone($timezone)->startOfDay();
        $ends_on = WeeklyPointGoal::weekEndsAt($week_starts_at, $household)->setTimezone($timezone)->startOfDay();

        return round($starts_on->diffInDays($ends_on)) / self::DAYS_PER_WEEK;
    }

    /**
     * The points of the instant (non-recurring) tasks that were open during the period: created before the period ended
     * and not completed before the period started. An unfinished task therefore carries over to the next period.
     * A penalty task instance is one member's extra work, it does not raise everyone's goal, see AssignWeeklyGoalPenaltyAction.
     *
     * @param  Collection<int, int>  $member_ids
     */
    private function instantPoints(Household $household, Collection $member_ids, CarbonImmutable $week_starts_at): float
    {
        $week_ends_at = WeeklyPointGoal::weekEndsAt($week_starts_at, $household);

        return $household->tasks()
            ->withTrashed()
            ->oneOff()
            ->with([
                'userWeights' => fn ($query) => $query->whereIn('user_id', $member_ids),
                'taskInstances' => fn ($query) => $query
                    ->whereDoesntHave('taskInstanceUsers', fn ($query) => $query->withTrashed()->whereNotNull('weekly_point_goal_id'))
                    ->where('created_at', '<', $week_ends_at)
                    ->where(fn ($query) => $query->whereNull('completed_at')->orWhere('completed_at', '>=', $week_starts_at)),
            ])
            ->get()
            ->sum(fn (Task $task): float => $this->taskPoints($task, $member_ids) * $task->taskInstances->count());
    }

    /**
     * The part of the period (0-1) that is left after the given moment, 1 when no period is given.
     */
    public function activeFraction(?DateTimeInterface $since, ?CarbonImmutable $week_starts_at, ?Household $household = null): float
    {
        if (! $since || ! $week_starts_at) {
            return 1.0;
        }

        $week_ends_at = WeeklyPointGoal::weekEndsAt($week_starts_at, $household);
        $active_from = CarbonImmutable::instance($since)->max($week_starts_at);
        if ($active_from->greaterThanOrEqualTo($week_ends_at)) {
            return 0.0;
        }

        return $active_from->diffInSeconds($week_ends_at) / $week_starts_at->diffInSeconds($week_ends_at);
    }

    /**
     * @param  Collection<int, int>  $member_ids
     */
    private function weeklyPoints(Task $task, Collection $member_ids): float
    {
        return $this->taskPoints($task, $member_ids) * $this->occurrencesPerWeek($task);
    }

    /**
     * The points of one occurrence. The claimers share them, so a task is worth the same however many members do it.
     *
     * @param  Collection<int, int>  $member_ids
     */
    private function taskPoints(Task $task, Collection $member_ids): float
    {
        return $task->base_points * CalculateTaskPointsAction::make()->weightMultiplier($task, $member_ids);
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
