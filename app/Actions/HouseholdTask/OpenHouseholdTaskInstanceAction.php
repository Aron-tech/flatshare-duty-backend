<?php

namespace App\Actions\HouseholdTask;

use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

class OpenHouseholdTaskInstanceAction
{
    use AsAction;

    /**
     * Opens a new, unclaimed task instance of a non-recurring task, which any member can claim.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, Task $task): TaskInstance
    {
        if ($task->household_id !== $household->id || ! $user->households()->whereKey($household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        if ($task->is_recurring) {
            throw new AuthorizationException(__('app.task_not_openable'));
        }

        return $task->taskInstances()->create([
            'household_id' => $household->id,
            'status' => TaskInstanceStatusEnum::PENDING,
        ]);
    }

    public function asController(Request $request, Household $household, Task $task): JsonResponse
    {
        try {
            $task_instance = $this->handle($request->user(), $household, $task);

            return response()->json(['task_instance' => $task_instance, 'message' => __('app.success_action')]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
