<?php

namespace App\Actions;

use App\Models\Household;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class ClaimTaskInstanceAction
{
    use AsAction;

    /**
     * The points are shared between the claimers, see CalculateTaskPointsAction, so nobody can join
     * once a claimer has completed their part and got their share. The exception is an overdue instance:
     * the part of a released claim has to be taken over, see ReleaseOverdueTaskClaimsAction.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, TaskInstance $task_instance): TaskInstanceUser
    {
        return DB::transaction(function () use ($user, $task_instance): TaskInstanceUser {
            $task_instance = TaskInstance::query()->with('task')->lockForUpdate()->findOrFail($task_instance->id);
            $claims = $task_instance->taskInstanceUsers()->get();

            if (! $task_instance->isOpen() || $claims->contains('user_id', $user->id) || ! $task_instance->isJoinable($claims)) {
                throw new AuthorizationException(__('app.task_instance_not_claimable'));
            }

            return $task_instance->claimFor($user->id);
        });
    }

    /**
     * @return array{message: string}
     */
    public function asController(#[CurrentUser] User $user, Household $household, TaskInstance $task_instance): array
    {
        $this->handle($user, $task_instance);

        return ['message' => __('app.success_action')];
    }
}
