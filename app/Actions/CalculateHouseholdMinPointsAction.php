<?php

namespace App\Actions;

use App\Enums\RecurrenceUnitEnum;
use App\Enums\ResetPeriodEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\WeeklyPointGoal;
use Carbon\CarbonImmutable;
use Closure;
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
     * The number of previous closed periods the expected instant task points are learned from, see learnedInstantPoints().
     */
    private const int LEARNING_WEEKLY_PERIODS = 4;

    private const int LEARNING_MONTHLY_PERIODS = 3;

    /**
     * The weight of each earlier period relative to the one after it, so the recent periods weigh more.
     */
    private const float LEARNING_DECAY = 0.5;

    /**
     * Calculates the points every member has to earn in the household's goal period (a week or a month) so the chores get done.
     * Only the base points and the common weight of the members are used, the overdue bounty is ignored.
     * The weekly point pool of all the tasks, scaled to the length of the period, is split evenly between the members.
     * With a given period, a recurring task added during that period only counts for the remaining part of the period.
     * Instant (non-recurring) tasks count in full in every period they were open in, see instantPoints().
     * Until the period ends, the instant tasks created in it count at least as much as the previous periods suggest.
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
        $period_points += $this->instantPoints($household, $member_ids, $period_starts_at, $weeks_in_period);

        return (int) round($period_points / $member_ids->count());
    }

    /**
     * The length of the period in weeks, counted in calendar days so a daylight saving change does not matter.
     */
    public function weeksInPeriod(Household $household, CarbonImmutable $week_starts_at): float
    {
        return $this->weeksBetween($week_starts_at, WeeklyPointGoal::weekEndsAt($week_starts_at, $household));
    }

    private function weeksBetween(CarbonImmutable $starts_at, CarbonImmutable $ends_at): float
    {
        $timezone = config('app.week_timezone');

        return round($starts_at->setTimezone($timezone)->startOfDay()->diffInDays($ends_at->setTimezone($timezone)->startOfDay())) / self::DAYS_PER_WEEK;
    }

    /**
     * The points of the instant (non-recurring) tasks that were open during the period: created before the period ended
     * and not completed before the period started. An unfinished task therefore carries over to the next period.
     * Until the period ends, the tasks created in it count at least the learned points, see learnedInstantPoints().
     * A closed period counts only the tasks that really existed, so nobody is penalized for a task that was never created.
     *
     * @param  Collection<int, int>  $member_ids
     */
    private function instantPoints(Household $household, Collection $member_ids, CarbonImmutable $week_starts_at, float $weeks_in_period): float
    {
        $week_ends_at = WeeklyPointGoal::weekEndsAt($week_starts_at, $household);

        [$created_points, $carried_over_points] = $this->instantTaskInstances($household, $member_ids, fn ($query) => $query
            ->where('created_at', '<', $week_ends_at)
            ->where(fn ($query) => $query->whereNull('completed_at')->orWhere('completed_at', '>=', $week_starts_at)))
            ->partition(fn (array $instance): bool => $instance['created_at']->greaterThanOrEqualTo($week_starts_at))
            ->map(fn (Collection $instances): float => $instances->sum('points'))
            ->all();

        if ($week_ends_at->greaterThan(now())) {
            $created_points = max($created_points, $this->learnedInstantPoints($household, $member_ids, $week_starts_at, $weeks_in_period));
        }

        return $carried_over_points + $created_points;
    }

    /**
     * The instant task points expected to be created in the period, learned from the household's previous closed periods:
     * the weighted average of the instant task points created per week, where each earlier period weighs LEARNING_DECAY times the next one.
     * A period lasts until the start of the next one, so a change of the reset period does not distort the weekly rate.
     * A period that started before the household was created is skipped, as it only covers a part of the period.
     *
     * @param  Collection<int, int>  $member_ids
     */
    private function learnedInstantPoints(Household $household, Collection $member_ids, CarbonImmutable $week_starts_at, float $weeks_in_period): float
    {
        $period_count = $household->resetPeriod() === ResetPeriodEnum::MONTHLY ? self::LEARNING_MONTHLY_PERIODS : self::LEARNING_WEEKLY_PERIODS;
        $period_starts = WeeklyPointGoal::query()
            ->where('household_id', $household->id)
            ->whereNotNull('closed_at')
            ->whereDate('week_starts_at', '<', WeeklyPointGoal::weekDate($week_starts_at))
            ->distinct()
            ->orderByDesc('week_starts_at')
            ->limit($period_count)
            ->pluck('week_starts_at')
            ->map(fn (DateTimeInterface $date): CarbonImmutable => CarbonImmutable::parse($date->format('Y-m-d'), config('app.week_timezone'))->utc());

        $periods = collect();
        $ends_at = $week_starts_at;
        foreach ($period_starts as $starts_at) {
            if ($starts_at->greaterThanOrEqualTo($household->created_at)) {
                $periods->push(['starts_at' => $starts_at, 'ends_at' => $ends_at]);
            }
            $ends_at = $starts_at;
        }

        if ($periods->isEmpty()) {
            return 0.0;
        }

        $instances = $this->instantTaskInstances($household, $member_ids, fn ($query) => $query
            ->where('created_at', '>=', $periods->last()['starts_at'])
            ->where('created_at', '<', $periods->first()['ends_at']));

        $weighted_rate = 0.0;
        $total_weight = 0.0;
        foreach ($periods as $index => $period) {
            $weeks = $this->weeksBetween($period['starts_at'], $period['ends_at']);
            if ($weeks <= 0) {
                continue;
            }

            $points = $instances
                ->filter(fn (array $instance): bool => $instance['created_at']->greaterThanOrEqualTo($period['starts_at']) && $instance['created_at']->lessThan($period['ends_at']))
                ->sum('points');
            $weight = self::LEARNING_DECAY ** $index;
            $weighted_rate += $weight * $points / $weeks;
            $total_weight += $weight;
        }

        return $total_weight > 0 ? $weighted_rate / $total_weight * $weeks_in_period : 0.0;
    }

    /**
     * The instances of the household's instant tasks matching the constraint, with the points of one occurrence.
     * A penalty task instance is one member's extra work, it does not raise everyone's goal, see AssignWeeklyGoalPenaltyAction.
     *
     * @param  Collection<int, int>  $member_ids
     * @return Collection<int, array{created_at: CarbonImmutable, points: float}>
     */
    private function instantTaskInstances(Household $household, Collection $member_ids, Closure $constraint): Collection
    {
        return $household->tasks()
            ->withTrashed()
            ->oneOff()
            ->with([
                'userWeights' => fn ($query) => $query->whereIn('user_id', $member_ids),
                'taskInstances' => fn ($query) => $constraint($query
                    ->whereDoesntHave('taskInstanceUsers', fn ($query) => $query->withTrashed()->whereNotNull('weekly_point_goal_id'))),
            ])
            ->get()
            ->flatMap(fn (Task $task): Collection => $task->taskInstances->map(fn (TaskInstance $instance): array => [
                'created_at' => CarbonImmutable::instance($instance->created_at),
                'points' => $this->taskPoints($task, $member_ids),
            ]));
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
