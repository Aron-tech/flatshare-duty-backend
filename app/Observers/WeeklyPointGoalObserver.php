<?php

namespace App\Observers;

use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskUserWeight;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates the current week's minimum points when a task or a member weight of the household changes.
 */
class WeeklyPointGoalObserver
{
    /**
     * Only these attributes affect the minimum points, e.g. renaming or assigning a task does not.
     */
    private const array TASK_POINT_ATTRIBUTES = ['base_points', 'is_recurring', 'recurrence_interval', 'recurrence_unit', 'max_user', 'deleted_at'];

    /**
     * The changes are checked right away, a later save in the same transaction would overwrite them.
     */
    public function saved(Task|TaskUserWeight $model): void
    {
        $point_attributes = $model instanceof Task ? self::TASK_POINT_ATTRIBUTES : ['weight'];

        if ($model->wasRecentlyCreated || $model->wasChanged($point_attributes)) {
            $this->recalculateAfterCommit($model->household_id);
        }
    }

    public function deleted(Task|TaskUserWeight $model): void
    {
        $this->recalculateAfterCommit($model->household_id);
    }

    private function recalculateAfterCommit(int $household_id): void
    {
        DB::afterCommit(function () use ($household_id) {
            $household = Household::find($household_id);
            if ($household) {
                RecalculateWeeklyPointGoalsAction::run($household);
            }
        });
    }
}
