<?php

namespace App\Actions\WeeklyPointGoal;

use App\Actions\PushToken\SendPushNotificationAction;
use App\Concerns\WritesCommandOutput;
use App\Enums\PointTransactionType;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\TaskInstanceUser;
use App\Models\WeeklyPointGoal;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class CloseWeeklyPointGoalsAction
{
    use AsAction;
    use WritesCommandOutput;

    public string $commandSignature = 'points:close-week {--output : Eredmény kiírása a konzolra}';

    public string $commandDescription = 'Lezárja a háztartások lejárt (heti vagy havi) minimum pontszámait: levonja a minimumot fedező pontokat, és büntető feladatot oszt ki annak, aki nem érte el.';

    /**
     * Closes every goal of the households' past periods and prepares the goals of their current period.
     * Each household has its own weekly or monthly period, see Household::resetPeriod().
     *
     * @return int the number of penalized goals
     */
    public function handle(): int
    {
        $penalized = 0;

        Household::query()->lazyById()->each(function (Household $household) use (&$penalized) {
            $penalized += $this->closeHousehold($household);
        });

        return $penalized;
    }

    /**
     * The goals of the previous period are calculated first unless it is already closed,
     * so the members are penalized even when nobody opened the app during that period.
     *
     * @return int the number of penalized goals
     */
    private function closeHousehold(Household $household): int
    {
        $recalculate = RecalculateWeeklyPointGoalsAction::make();
        $current_period = WeeklyPointGoal::weekStartsAt(null, $household);
        $previous_period = WeeklyPointGoal::weekStartsAt($current_period->subDay(), $household);

        if (! $recalculate->goalsOfWeek($household, $previous_period)->whereNotNull('closed_at')->exists()) {
            $recalculate->handle($household, $previous_period);
        }
        $recalculate->handle($household, $current_period);

        $penalized = 0;
        WeeklyPointGoal::query()
            ->where('household_id', $household->id)
            ->whereNull('closed_at')
            ->whereDate('week_starts_at', '<', WeeklyPointGoal::weekDate($current_period))
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
     * Deducts the points that covered the minimum from the balance, so only the extra points are kept for the rewards.
     * The member is penalized when they earned less than the tolerated part of the minimum, see WeeklyPointGoal::penaltyPoints().
     *
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
            $this->settle($goal, $goal->settledPoints($earned_points));

            $penalty_points = $goal->penaltyPoints($earned_points);

            return $penalty_points > 0 ? AssignWeeklyGoalPenaltyAction::run($goal, $penalty_points) : collect();
        });
    }

    private function settle(WeeklyPointGoal $goal, int $settled_points): void
    {
        $household_user = HouseholdUser::query()
            ->where('household_id', $goal->household_id)
            ->where('user_id', $goal->user_id)
            ->lockForUpdate()
            ->first();

        $settled_points = min($settled_points, $household_user?->points_balance ?? 0);
        if ($settled_points <= 0) {
            return;
        }

        $household_user->decrement('points_balance', $settled_points);

        PointTransaction::create([
            'household_id' => $goal->household_id,
            'user_id' => $goal->user_id,
            'amount' => $settled_points,
            'balance_after' => $household_user->points_balance,
            'type' => PointTransactionType::WEEKLY_GOAL_SETTLEMENT,
        ]);
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
        $this->writeOutput($command, "Büntetések: {$this->handle()}");
    }
}
