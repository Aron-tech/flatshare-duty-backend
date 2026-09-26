<?php

namespace App\Actions\HouseholdTask;

use App\Actions\RecurringTask\SyncTaskAssignmentAction;
use App\Http\Requests\StoreHouseholdTaskRequest;
use App\Models\Household;
use App\Models\Task;
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
class StoreHouseholdTaskAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $data  see StoreHouseholdTaskRequest
     */
    public function handle(User $user, Household $household, array $data): Task
    {
        return DB::transaction(function () use ($household, $user, $data): Task {
            $task = $household->tasks()->create([
                ...Arr::except($data, SyncTaskAssignmentAction::ATTRIBUTES),
                'created_by' => $user->id,
            ]);

            return SyncTaskAssignmentAction::make()->handle($task, $data);
        });
    }

    /**
     * @return array{household: Household, message: string}
     */
    public function asController(StoreHouseholdTaskRequest $request, #[CurrentUser] User $user, Household $household): array
    {
        $this->handle($user, $household, $request->validated());

        return ['household' => $household, 'message' => __('app.success_action')];
    }
}
