<?php

namespace App\Actions\HouseholdUser;

use App\Actions\RecurringTask\SyncTaskAssignmentAction;
use App\Enums\TaskAssignmentModeEnum;
use App\Models\Task;
use App\Models\TaskUserRotation;
use Lorisleiva\Actions\Concerns\AsAction;

class ReleaseMemberAssignmentsAction
{
    use AsAction;

    /**
     * Frees the recurring tasks assigned to a member leaving the household: a task fixed to them becomes unassigned,
     * and they are removed from the rotations. A rotation left without members becomes unassigned too.
     * The next instances go to the pool (or the next member of the rotation) instead of being skipped.
     */
    public function handle(int $household_id, int $user_id): void
    {
        Task::query()
            ->where('household_id', $household_id)
            ->where('assignment_mode', TaskAssignmentModeEnum::FIXED)
            ->where('fixed_user_id', $user_id)
            ->each(fn (Task $task) => SyncTaskAssignmentAction::run($task, ['assignment_mode' => TaskAssignmentModeEnum::NONE->value]));

        $rotations = TaskUserRotation::query()
            ->where('user_id', $user_id)
            ->whereHas('task', fn ($query) => $query->where('household_id', $household_id));
        $rotating_task_ids = (clone $rotations)->pluck('task_id');
        $rotations->delete();

        Task::query()
            ->whereIn('id', $rotating_task_ids)
            ->where('assignment_mode', TaskAssignmentModeEnum::ROTATING)
            ->whereDoesntHave('rotations')
            ->each(fn (Task $task) => SyncTaskAssignmentAction::run($task, ['assignment_mode' => TaskAssignmentModeEnum::NONE->value]));
    }
}
