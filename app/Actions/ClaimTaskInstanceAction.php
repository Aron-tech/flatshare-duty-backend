<?php

namespace App\Actions;

use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class ClaimTaskInstanceAction
{
    use AsAction;

    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, TaskInstance $task_instance): TaskInstanceUser
    {
        if (
            $task_instance->household_id !== $household->id
            || ! $user->households()->whereKey($household->id)->exists()
        ) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        return DB::transaction(function () use ($user, $task_instance) {
            $task_instance = TaskInstance::query()->with('task')->lockForUpdate()->findOrFail($task_instance->id);

            $is_open = $task_instance->status === TaskInstanceStatusEnum::PENDING && ! $task_instance->completed_at;
            $claims = $task_instance->taskInstanceUsers()->get();

            if (! $is_open || $claims->contains('user_id', $user->id) || $claims->count() >= $task_instance->task->max_user) {
                throw new AuthorizationException(__('app.task_instance_not_claimable'));
            }

            return $task_instance->taskInstanceUsers()->create(['user_id' => $user->id]);
        });
    }

    public function asController(Request $request, Household $household, TaskInstance $task_instance): JsonResponse
    {
        try {
            $this->handle($request->user(), $household, $task_instance);

            return response()->json(['message' => __('app.success_action')]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
