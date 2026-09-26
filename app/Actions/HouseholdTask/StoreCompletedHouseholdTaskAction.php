<?php

namespace App\Actions\HouseholdTask;

use App\Http\Requests\StoreHouseholdTaskRequest;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class StoreCompletedHouseholdTaskAction
{
    use AsAction;

    /**
     * Adds a non-recurring custom task to the household and logs it as done by the user.
     * The task instance created along with the task is the one that gets completed.
     *
     * @param  array<string, mixed>  $data  see StoreHouseholdTaskRequest
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, array $data): PointTransaction
    {
        return DB::transaction(function () use ($user, $household, $data): PointTransaction {
            $task = StoreHouseholdTaskAction::make()->handle($user, $household, [
                ...$data,
                'is_recurring' => false,
                'recurrence_interval' => null,
                'recurrence_unit' => null,
            ]);

            return LogHouseholdTaskCompletionAction::make()->handle($user, $household, $task, $task->taskInstances()->first());
        });
    }

    /**
     * @return array{points: int, points_balance: int, message: string}
     */
    public function asController(StoreHouseholdTaskRequest $request, #[CurrentUser] User $user, Household $household): array
    {
        return LogHouseholdTaskCompletionAction::pointsResponse($this->handle($user, $household, $request->validated()));
    }
}
