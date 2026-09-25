<?php

namespace App\Actions\HouseholdTask;

use App\Http\Requests\StoreHouseholdTaskRequest;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class StoreCompletedHouseholdTaskAction
{
    use AsAction;

    /**
     * Adds a non-recurring custom task to the household and logs it as done by the user.
     * The task instance created along with the task is the one that gets completed.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, array $data): PointTransaction
    {
        return DB::transaction(function () use ($user, $household, $data) {
            $task = StoreHouseholdTaskAction::make()->handle($user, $household, [
                ...$data,
                'is_recurring' => false,
                'recurrence_interval' => null,
                'recurrence_unit' => null,
            ]);
            if (! $task) {
                throw new AuthorizationException(__('app.no_permission'));
            }

            return LogHouseholdTaskCompletionAction::make()->handle($user, $household, $task, $task->taskInstances()->first());
        });
    }

    public function asController(StoreHouseholdTaskRequest $request, Household $household): JsonResponse
    {
        try {
            $point_transaction = $this->handle($request->user(), $household, $request->validated());

            return response()->json([
                'points' => $point_transaction->amount,
                'points_balance' => $point_transaction->balance_after,
                'message' => __('app.success_action'),
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
