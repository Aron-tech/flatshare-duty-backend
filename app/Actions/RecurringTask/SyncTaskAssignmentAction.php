<?php

namespace App\Actions\RecurringTask;

use App\Enums\TaskAssignmentModeEnum;
use App\Models\Task;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncTaskAssignmentAction
{
    use AsAction;

    /**
     * The request fields describing the assignment, they are not task attributes.
     */
    public const array ATTRIBUTES = ['assignment_mode', 'fixed_user_id', 'rotation_user_ids'];

    /**
     * Saves who a recurring task is assigned to: a fixed member, a rotation between members, or nobody.
     * A rotation without an explicit member list rotates between all members of the household.
     * Non-recurring tasks are never assigned, and the unused assignment data is cleared.
     * The currently open, still unclaimed instance is assigned right away.
     *
     * @param  array{assignment_mode?: ?string, fixed_user_id?: ?int, rotation_user_ids?: ?list<int>}  $data
     */
    public function handle(Task $task, array $data): Task
    {
        $mode = $task->is_recurring
            ? TaskAssignmentModeEnum::tryFrom($data['assignment_mode'] ?? '') ?? $task->assignment_mode ?? TaskAssignmentModeEnum::NONE
            : TaskAssignmentModeEnum::NONE;

        if ($mode === TaskAssignmentModeEnum::FIXED && ! ($data['fixed_user_id'] ?? $task->fixed_user_id)) {
            $mode = TaskAssignmentModeEnum::NONE;
        }

        $task->assignment_mode = $mode;
        $task->fixed_user_id = $mode === TaskAssignmentModeEnum::FIXED ? ($data['fixed_user_id'] ?? $task->fixed_user_id) : null;

        if ($mode !== TaskAssignmentModeEnum::ROTATING) {
            $task->last_assigned_user_id = null;
        }
        $task->save();

        if ($mode !== TaskAssignmentModeEnum::ROTATING) {
            $task->rotations()->delete();
        } elseif (array_key_exists('rotation_user_ids', $data) || $task->rotations()->doesntExist()) {
            $this->syncRotation($task, $data['rotation_user_ids'] ?? null);
        }

        if ($mode !== TaskAssignmentModeEnum::NONE) {
            $open_instance = $task->taskInstances()
                ->open()
                ->latest('id')
                ->first();

            if ($open_instance) {
                AssignTaskInstanceAction::make()->handle($open_instance->setRelation('task', $task));
            }
        }

        return $task;
    }

    /**
     * @param  ?list<int>  $user_ids
     */
    private function syncRotation(Task $task, ?array $user_ids): void
    {
        $user_ids = collect($user_ids ?: $task->household->householdUsers()->orderBy('id')->pluck('user_id'))->unique()->values();

        $task->rotations()->delete();
        $task->rotations()->createMany($user_ids->map(fn (int $user_id, int $order): array => ['user_id' => $user_id, 'rotation_order' => $order]));
    }
}
