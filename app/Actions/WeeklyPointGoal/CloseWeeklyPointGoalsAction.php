<?php

namespace App\Actions\WeeklyPointGoal;

use App\Actions\PushToken\SendPushNotificationAction;
use App\Models\Household;
use App\Models\TaskInstanceUser;
use App\Models\WeeklyPointGoal;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class CloseWeeklyPointGoalsAction
{
    use AsAction;

    public string $commandSignature = 'points:close-week';

    public string $commandDescription = 'Lezárja az előző hét minimum pontszámait és büntető feladatot oszt ki annak, aki nem érte el.';

    /**
     * No penalty when the member earned at least this part of the minimum points.
     */
    public const float TOLERANCE = 0.9;

    /**
     * Closes every goal of the past weeks and prepares the goals of the current week.
     *
     * @return int the number of penalized goals
     */
    public function handle(): int
    {
        $current_week = CarbonImmutable::now()->startOfWeek();
        $previous_week = $current_week->subWeek();

        Household::query()->lazyById()->each(function (Household $household) use ($previous_week, $current_week) {
            RecalculateWeeklyPointGoalsAction::run($household, $previous_week);
            RecalculateWeeklyPointGoalsAction::run($household, $current_week);
        });

        $penalized = 0;
        WeeklyPointGoal::query()
            ->whereHas('household')
            ->whereNull('closed_at')
            ->whereDate('week_starts_at', '<', $current_week->toDateString())
            ->lazyById()
            ->each(function (WeeklyPointGoal $goal) use (&$penalized) {
                $penalty_claims = $this->close($goal);
                if ($penalty_claims->isNotEmpty()) {
                    $penalized++;
                    $this->notify($goal->refresh(), $penalty_claims);
                }
            });

        return $penalized;
    }

    /**
     * @return Collection<int, TaskInstanceUser> the assigned penalty claims
     */
    public function close(WeeklyPointGoal $goal): Collection
    {
        return DB::transaction(function () use ($goal) {
            $goal = WeeklyPointGoal::query()->lockForUpdate()->findOrFail($goal->id);
            if ($goal->closed_at) {
                return collect();
            }

            $earned_points = $goal->calculateEarnedPoints();
            $goal->update([
                'earned_points' => $earned_points,
                'shortfall_points' => max(0, $goal->target_points - $earned_points),
                'closed_at' => now(),
            ]);

            $is_penalized = $goal->target_points > 0 && $earned_points < $goal->target_points * self::TOLERANCE;

            return $is_penalized ? AssignWeeklyGoalPenaltyAction::run($goal) : collect();
        });
    }

    /**
     * @param  Collection<int, TaskInstanceUser>  $penalty_claims
     */
    private function notify(WeeklyPointGoal $goal, Collection $penalty_claims): void
    {
        $locale = $goal->user->language?->value;

        SendPushNotificationAction::run(
            [$goal->user_id],
            __('app.weekly_goal_penalty_title', ['household' => $goal->household->name], $locale),
            __('app.weekly_goal_penalty_body', [
                'earned' => $goal->earned_points,
                'target' => $goal->target_points,
                'tasks' => $penalty_claims->map(fn (TaskInstanceUser $claim) => $claim->taskInstance->task->name)->implode(', '),
            ], $locale),
            ['route' => '/', 'household_id' => $goal->household_id],
        );
    }

    public function asCommand(Command $command): void
    {
        $command->info("Büntetések: {$this->handle()}");
    }
}
