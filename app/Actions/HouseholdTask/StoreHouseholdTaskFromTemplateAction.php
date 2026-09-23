<?php

namespace App\Actions;

use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class StoreHouseholdTaskFromTemplateAction
{
    use AsAction;

    public function handle(User $user, Household $household, TaskTemplate $task_template): ?Task
    {
        $household_user = HouseholdUser::query()->where('user_id', $user->id)->where('household_id', $household->id)->first();
        if (!$household_user) return $household_user;
        $task = Task::loadFromTemplate($task_template);
        return DB::transaction(fn () => $household->tasks()->save($task));
    }

    public function asController(Request $request, Household $household, TaskTemplate $task_template): JsonResponse
    {
        try {
            if(!$this->handle($request->user(), $household, $task_template)) {
                return response()->json(['message' => __('app.no_permission')], 403);
            }
            return response()->json(['household' => $household, 'message' => __('app.success_action')]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
