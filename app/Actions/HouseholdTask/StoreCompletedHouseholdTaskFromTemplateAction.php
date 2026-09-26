<?php

namespace App\Actions\HouseholdTask;

use App\Http\Requests\StoreHouseholdTaskFromTemplateRequest;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class StoreCompletedHouseholdTaskFromTemplateAction
{
    use AsAction;

    /**
     * Adds a non-recurring task from the template to the household and logs it as done by the user.
     * The task instance created along with the task is the one that gets completed.
     *
     * @param  array{max_user?: ?int}  $data
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, TaskTemplate $task_template, array $data = []): PointTransaction
    {
        return DB::transaction(function () use ($user, $household, $task_template, $data): PointTransaction {
            $task = StoreHouseholdTaskFromTemplateAction::make()->handle($user, $household, $task_template, [
                'max_user' => $data['max_user'] ?? null,
                'is_recurring' => false,
            ]);

            return LogHouseholdTaskCompletionAction::make()->handle($user, $household, $task, $task->taskInstances()->first());
        });
    }

    /**
     * @return array{points: int, points_balance: int, message: string}
     */
    public function asController(StoreHouseholdTaskFromTemplateRequest $request, #[CurrentUser] User $user, Household $household, TaskTemplate $task_template): array
    {
        return LogHouseholdTaskCompletionAction::pointsResponse($this->handle($user, $household, $task_template, $request->validated()));
    }
}
