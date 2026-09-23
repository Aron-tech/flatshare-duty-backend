<?php

namespace App\Actions\HouseholdTask;

use App\Http\Requests\StoreHouseholdTaskRequest;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class StoreHouseholdTaskAction
{
    use AsAction;

    public function handle(User $user, Household $household, array $data): ?Task
    {
        $household_user = HouseholdUser::query()->where('user_id', $user->id)->where('household_id', $household->id)->first();
        if (!$household_user) return $household_user;
        return DB::transaction(fn () => $household->tasks()->create([
            ...$data,
            'created_by' => $user->id
        ]));
    }

    public function asController(StoreHouseholdTaskRequest $request, Household $household): JsonResponse
    {
        try {
            if(!$this->handle($request->user(), $household, $request->validated())) {
                return response()->json(['message' => __('app.no_permission')], 403);
            }
            return response()->json(['household' => $household, 'message' => __('app.success_action')]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
