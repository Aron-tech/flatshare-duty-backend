<?php

namespace App\Actions\WeeklyPointGoal;

use App\Actions\PushToken\SendPushNotificationAction;
use App\Concerns\WritesCommandOutput;
use App\Models\Household;
use App\Models\WeeklyPointGoal;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;

class RemindWeeklyPointGoalsAction
{
    use AsAction;
    use WritesCommandOutput;

    public string $commandSignature = 'points:remind-week {--output : Eredmény kiírása a konzolra}';

    public string $commandDescription = 'Push emlékeztető azoknak, akik az időszak utolsó napján még nem érték el a minimum pontszámot.';

    /**
     * Notifies once per period every member who has not reached the current period's minimum points
     * by the last day of the period (weekly or monthly, see Household::resetPeriod()).
     *
     * @return int the number of notified goals
     */
    public function handle(): int
    {
        $reminded = 0;

        Household::query()->lazyById()->each(function (Household $household) use (&$reminded) {
            RecalculateWeeklyPointGoalsAction::make()->currentGoals($household)
                ->filter(fn (WeeklyPointGoal $goal) => ! $goal->reminded_at && $goal->target_points > 0 && WeeklyPointGoal::weekEndsAt($goal->startsAt(), $household)->subDay()->lessThanOrEqualTo(now()))
                ->each(function (WeeklyPointGoal $goal) use ($household, &$reminded) {
                    $missing_points = $goal->target_points - $goal->calculateEarnedPoints();
                    if ($missing_points <= 0) {
                        return;
                    }

                    $locale = $goal->user->language?->value;
                    SendPushNotificationAction::run(
                        [$goal->user_id],
                        __('app.weekly_goal_reminder_title', ['household' => $household->name], $locale),
                        __('app.weekly_goal_reminder_body', ['missing' => $missing_points, 'target' => $goal->target_points], $locale),
                        ['route' => '/', 'household_id' => $household->id],
                    );
                    $goal->update(['reminded_at' => now()]);
                    $reminded++;
                });
        });

        return $reminded;
    }

    public function asCommand(Command $command): void
    {
        $this->writeOutput($command, "Emlékeztetők: {$this->handle()}");
    }
}
