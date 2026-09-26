<?php

namespace App\Actions\HouseholdTask;

use App\Actions\RecurringTask\SyncTaskAssignmentAction;
use App\Http\Requests\StoreHouseholdTaskFromTemplateRequest;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Any member can add a task to the household.
 */
#[Authorize('view', 'household')]
class StoreHouseholdTaskFromTemplateAction
{
    use AsAction;

    /**
     * @param  array{is_recurring?: ?bool, recurrence_interval?: ?int, recurrence_unit?: ?string, max_user?: ?int, assignment_mode?: ?string, fixed_user_id?: ?int, rotation_user_ids?: ?list<int>}  $data
     */
    public function handle(User $user, Household $household, TaskTemplate $task_template, array $data = []): Task
    {
        $task = Task::loadFromTemplate($task_template, [
            ...array_filter(Arr::except($data, SyncTaskAssignmentAction::ATTRIBUTES), fn (mixed $value): bool => $value !== null),
            'task_template_id' => $task_template->id,
            'created_by' => $user->id,
        ]);

        return DB::transaction(function () use ($household, $task, $data): Task {
            $household->tasks()->save($task);

            return SyncTaskAssignmentAction::make()->handle($task, $data);
        });
    }

    /**
     * @return array{household: Household, message: string}
     */
    public function asController(StoreHouseholdTaskFromTemplateRequest $request, #[CurrentUser] User $user, Household $household, TaskTemplate $task_template): array
    {
        $this->handle($user, $household, $task_template, $request->validated());

        return ['household' => $household, 'message' => __('app.success_action')];
    }
}
