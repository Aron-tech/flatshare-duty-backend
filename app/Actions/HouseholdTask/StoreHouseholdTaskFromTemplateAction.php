<?php

namespace App\Actions\HouseholdTask;

use App\Actions\RecurringTask\SyncTaskAssignmentAction;
use App\Http\Requests\StoreHouseholdTaskFromTemplateRequest;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class StoreHouseholdTaskFromTemplateAction
{
    use AsAction;

    /**
     * @param  array{is_recurring?: ?bool, recurrence_interval?: ?int, recurrence_unit?: ?string, max_user?: ?int, assignment_mode?: ?string, fixed_user_id?: ?int, rotation_user_ids?: ?list<int>}  $data
     */
    public function handle(User $user, Household $household, TaskTemplate $task_template, array $data = []): ?Task
    {
        $household_user = HouseholdUser::query()->where('user_id', $user->id)->where('household_id', $household->id)->first();
        if (! $household_user) {
            return null;
        }

        $task = Task::loadFromTemplate($task_template, [
            ...array_filter(Arr::except($data, ['assignment_mode', 'fixed_user_id', 'rotation_user_ids']), fn ($value) => ! is_null($value)),
            'task_template_id' => $task_template->id,
            'created_by' => $user->id,
        ]);

        return DB::transaction(function () use ($household, $task, $data) {
            $household->tasks()->save($task);

            return SyncTaskAssignmentAction::make()->handle($task, $data);
        });
    }

    public function asController(StoreHouseholdTaskFromTemplateRequest $request, Household $household, TaskTemplate $task_template): JsonResponse
    {
        try {
            if (! $this->handle($request->user(), $household, $task_template, $request->validated())) {
                return response()->json(['message' => __('app.no_permission')], 403);
            }

            return response()->json(['household' => $household, 'message' => __('app.success_action')]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
