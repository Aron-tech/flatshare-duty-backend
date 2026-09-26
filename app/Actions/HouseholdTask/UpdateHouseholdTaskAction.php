<?php

namespace App\Actions\HouseholdTask;

use App\Actions\RecurringTask\SyncTaskAssignmentAction;
use App\Http\Requests\UpdateHouseholdTaskRequest;
use App\Models\Household;
use App\Models\Task;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Like deleting, a child member cannot edit tasks, see TaskPolicy.
 */
#[Authorize('update', 'task')]
class UpdateHouseholdTaskAction
{
    use AsAction;

    /**
     * Updates a task of the household, the base points are recalculated from the duration and difficulty.
     *
     * @param  array{name?: string, description?: ?string, category_id?: ?int, icon?: ?string, duration_minutes?: int, difficulty?: string, is_recurring?: bool, recurrence_interval?: ?int, recurrence_unit?: ?string, max_user?: int, assignment_mode?: ?string, fixed_user_id?: ?int, rotation_user_ids?: ?list<int>}  $data
     */
    public function handle(Task $task, array $data): Task
    {
        if (array_key_exists('is_recurring', $data) && ! $data['is_recurring']) {
            $data['recurrence_interval'] = null;
            $data['recurrence_unit'] = null;
        }

        DB::transaction(function () use ($task, $data): void {
            $task->fill(Arr::except($data, SyncTaskAssignmentAction::ATTRIBUTES))->calculateBasePoints()->save();
            SyncTaskAssignmentAction::make()->handle($task, $data);
        });

        return $task;
    }

    /**
     * @return array{task: Task, message: string}
     */
    public function asController(UpdateHouseholdTaskRequest $request, Household $household, Task $task): array
    {
        return ['task' => $this->handle($task, $request->validated())->load('category'), 'message' => __('app.success_action')];
    }
}
