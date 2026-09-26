<?php

namespace App\Actions\HouseholdTask;

use App\Actions\TaskOffer\ExpireTaskOffersAction;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskInstanceUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * A child member cannot delete tasks, see TaskPolicy.
 */
#[Authorize('delete', 'task')]
class DeleteHouseholdTaskAction
{
    use AsAction;

    /**
     * The pending instances and their claims are deleted with the task, the completed ones stay for the history.
     * The open offers of the deleted task instances are refunded right away, see ExpireTaskOffersAction.
     */
    public function handle(Task $task): bool
    {
        $is_deleted = DB::transaction(function () use ($task): bool {
            $pending_instances = $task->taskInstances()->where('status', TaskInstanceStatusEnum::PENDING);
            TaskInstanceUser::query()->whereIn('task_instance_id', (clone $pending_instances)->select('id'))->delete();
            $pending_instances->delete();
            $task->userWeights()->delete();

            return (bool) $task->delete();
        });

        ExpireTaskOffersAction::run($task->household_id);

        return $is_deleted;
    }

    /**
     * @return array{message: string}
     */
    public function asController(Household $household, Task $task): array
    {
        $this->handle($task);

        return ['message' => __('app.success_action')];
    }
}
