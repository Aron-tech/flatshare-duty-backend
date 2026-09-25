<?php

namespace App\Actions;

use App\Http\Requests\StoreTaskUserWeightRequest;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskUserWeight;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class StoreTaskUserWeightAction
{
    use AsAction;

    /**
     * @param  array{weight: string}  $data
     */
    public function handle(User $user, Household $household, Task $task, array $data): ?TaskUserWeight
    {
        $is_household_task = $task->household_id === $household->id;
        $is_household_user = $user->households()->whereKey($household->id)->exists();
        if (! ($is_household_task && $is_household_user)) {
            return null;
        }

        return DB::transaction(fn () => TaskUserWeight::updateOrCreate(
            [
                'household_id' => $household->id,
                'user_id' => $user->id,
                'task_id' => $task->id,
            ],
            [
                'weight' => $data['weight'],
            ]
        ));
    }

    public function asController(StoreTaskUserWeightRequest $request, Household $household, Task $task): JsonResponse
    {
        try {
            if (! $this->handle($request->user(), $household, $task, $request->validated())) {
                return response()->json(['message' => __('app.no_permission')], 403);
            }

            return response()->json(['household' => $household, 'message' => __('app.success_action')]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
