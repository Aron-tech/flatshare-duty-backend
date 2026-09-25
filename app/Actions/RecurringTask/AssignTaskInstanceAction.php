<?php

namespace App\Actions\RecurringTask;

use App\Enums\TaskAssignmentModeEnum;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use Lorisleiva\Actions\Concerns\AsAction;

class AssignTaskInstanceAction
{
    use AsAction;

    /**
     * Claims the task instance for the member the task is assigned to: the fixed member,
     * or the next member of the rotation. Members who left the household are skipped.
     * Nothing is assigned when the task has no assignment or the instance already has a claim.
     */
    public function handle(TaskInstance $task_instance): ?TaskInstanceUser
    {
        $task = $task_instance->task;

        if ($task_instance->taskInstanceUsers()->exists()) {
            return null;
        }

        $user_id = match ($task->assignment_mode) {
            TaskAssignmentModeEnum::FIXED => $task->fixed_user_id,
            TaskAssignmentModeEnum::ROTATING => $this->nextRotationUserId($task),
            default => null,
        };

        if (! $user_id || ! $task->household->householdUsers()->where('user_id', $user_id)->exists()) {
            return null;
        }

        if ($task->assignment_mode === TaskAssignmentModeEnum::ROTATING) {
            $task->update(['last_assigned_user_id' => $user_id]);
        }

        return $task_instance->taskInstanceUsers()->create(['user_id' => $user_id]);
    }

    private function nextRotationUserId(Task $task): ?int
    {
        $member_ids = $task->household->householdUsers()->pluck('user_id');
        $rotation = $task->rotations()->get()->filter(fn ($rotation) => $member_ids->contains($rotation->user_id))->values();

        if ($rotation->isEmpty()) {
            return null;
        }

        $last_index = $rotation->search(fn ($rotation) => $rotation->user_id === $task->last_assigned_user_id);

        return $rotation[$last_index === false ? 0 : ($last_index + 1) % $rotation->count()]->user_id;
    }
}
