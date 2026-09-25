<?php

namespace App\Actions\HouseholdTask;

use App\Actions\RecurringTask\SyncTaskAssignmentAction;
use App\Enums\RoleEnum;
use App\Http\Requests\UpdateHouseholdTaskRequest;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateHouseholdTaskAction
{
    use AsAction;

    /**
     * Updates a task of the household, the base points are recalculated from the duration and difficulty.
     * Like deleting, a child member cannot edit tasks.
     *
     * @param  array{name?: string, description?: ?string, category_id?: ?int, icon?: ?string, duration_minutes?: int, difficulty?: string, is_recurring?: bool, recurrence_interval?: ?int, recurrence_unit?: ?string, max_user?: int, assignment_mode?: ?string, fixed_user_id?: ?int, rotation_user_ids?: ?list<int>}  $data
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, Task $task, array $data): Task
    {
        $household_user = HouseholdUser::query()->where('household_id', $household->id)->where('user_id', $user->id)->first();
        if (! $household_user || $household_user->role === RoleEnum::CHILD || $task->household_id !== $household->id) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        if (array_key_exists('is_recurring', $data) && ! $data['is_recurring']) {
            $data['recurrence_interval'] = null;
            $data['recurrence_unit'] = null;
        }

        DB::transaction(function () use ($task, $data) {
            $task->fill(Arr::except($data, ['assignment_mode', 'fixed_user_id', 'rotation_user_ids']))->calculateBasePoints()->save();
            SyncTaskAssignmentAction::make()->handle($task, $data);
        });

        return $task;
    }

    public function asController(UpdateHouseholdTaskRequest $request, Household $household, Task $task): JsonResponse
    {
        try {
            $task = $this->handle($request->user(), $household, $task, $request->validated());

            return response()->json(['task' => $task->load('category'), 'message' => __('app.success_action')]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
