<?php

namespace App\Actions\HouseholdTask;

use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskInstance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class OpenHouseholdTaskInstanceAction
{
    use AsAction;

    /**
     * Opens a new, unclaimed task instance of a non-recurring task, which any member can claim.
     *
     * @throws AuthorizationException
     */
    public function handle(Task $task): TaskInstance
    {
        if ($task->is_recurring) {
            throw new AuthorizationException(__('app.task_not_openable'));
        }

        return $task->taskInstances()->create([
            'household_id' => $task->household_id,
            'status' => TaskInstanceStatusEnum::PENDING,
        ]);
    }

    /**
     * @return array{task_instance: TaskInstance, message: string}
     */
    public function asController(Household $household, Task $task): array
    {
        return ['task_instance' => $this->handle($task), 'message' => __('app.success_action')];
    }
}
