<?php

namespace App\Actions\RecurringTask;

use App\Enums\TaskAssignmentModeEnum;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use App\Models\TaskUserRotation;
use Illuminate\Support\Collection;
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

        $member_ids = $task->household->householdUsers()->pluck('user_id');
        $user_id = match ($task->assignment_mode) {
            TaskAssignmentModeEnum::FIXED => $task->fixed_user_id,
            TaskAssignmentModeEnum::ROTATING => $this->nextRotationUserId($task, $member_ids),
            default => null,
        };

        if (! $user_id || ! $member_ids->contains($user_id)) {
            return null;
        }

        if ($task->assignment_mode === TaskAssignmentModeEnum::ROTATING) {
            $task->update(['last_assigned_user_id' => $user_id]);
        }

        return $task_instance->taskInstanceUsers()->create(['user_id' => $user_id]);
    }

    /**
     * @param  Collection<int, int>  $member_ids
     */
    private function nextRotationUserId(Task $task, Collection $member_ids): ?int
    {
        $rotation = $task->rotations()->whereIn('user_id', $member_ids)->get();

        if ($rotation->isEmpty()) {
            return null;
        }

        $last_index = $rotation->search(fn (TaskUserRotation $rotation): bool => $rotation->user_id === $task->last_assigned_user_id);

        return $rotation[$last_index === false ? 0 : ($last_index + 1) % $rotation->count()]->user_id;
    }
}
