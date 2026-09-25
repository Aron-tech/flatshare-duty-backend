<?php

namespace App\Observers;

use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskUserWeight;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Recalculates the current week's minimum points when a task or a member weight of the household changes.
 */
class WeeklyPointGoalObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Task|TaskUserWeight $model): void
    {
        $this->recalculate($model);
    }

    public function deleted(Task|TaskUserWeight $model): void
    {
        $this->recalculate($model);
    }

    private function recalculate(Task|TaskUserWeight $model): void
    {
        $household = Household::find($model->household_id);
        if ($household) {
            RecalculateWeeklyPointGoalsAction::run($household);
        }
    }
}
