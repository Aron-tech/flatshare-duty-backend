<?php

namespace App\Actions\HouseholdTask;

use App\Http\Requests\StoreHouseholdTaskFromTemplateRequest;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

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
        return DB::transaction(function () use ($user, $household, $task_template, $data) {
            $task = StoreHouseholdTaskFromTemplateAction::make()->handle($user, $household, $task_template, [
                'max_user' => $data['max_user'] ?? null,
                'is_recurring' => false,
            ]);
            if (! $task) {
                throw new AuthorizationException(__('app.no_permission'));
            }

            return LogHouseholdTaskCompletionAction::make()->handle($user, $household, $task, $task->taskInstances()->first());
        });
    }

    public function asController(StoreHouseholdTaskFromTemplateRequest $request, Household $household, TaskTemplate $task_template): JsonResponse
    {
        try {
            $point_transaction = $this->handle($request->user(), $household, $task_template, $request->validated());

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
