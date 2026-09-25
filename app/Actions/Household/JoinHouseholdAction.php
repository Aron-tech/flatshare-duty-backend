<?php

namespace App\Actions\Household;

use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Enums\RoleEnum;
use App\Http\Requests\JoinHouseholdRequest;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class JoinHouseholdAction
{
    use AsAction;

    /**
     * @throws ModelNotFoundException
     */
    public function handle(User $user, string $join_code): Household
    {
        $household = Household::where('join_code', $join_code)->first();

        if (! $household) {
            throw (new ModelNotFoundException)->setModel(Household::class);
        }

        DB::transaction(
            fn () => $user->households()->syncWithoutDetaching([$household->id => ['role' => RoleEnum::USER]])
        );
        RecalculateWeeklyPointGoalsAction::run($household);

        return $household;
    }

    public function asController(JoinHouseholdRequest $request): JsonResponse
    {
        try {
            $this->handle($request->user(), $request->validated('code'));

            return response()->json(['message' => __('app.success_action')]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => __('app.not_found_household')], 404);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
