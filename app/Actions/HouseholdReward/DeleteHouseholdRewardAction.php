<?php

namespace App\Actions\HouseholdReward;

use App\Models\Household;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteHouseholdRewardAction
{
    use AsAction;

    /**
     * Only the creator of the reward or an admin of the household can delete it.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, Reward $reward): bool
    {
        if ($reward->household_id !== $household->id || ! $reward->canBeDeletedBy($user)) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        return DB::transaction(fn () => $reward->delete());
    }

    public function asController(Request $request, Household $household, Reward $reward): JsonResponse
    {
        try {
            $this->handle($request->user(), $household, $reward);

            return response()->json(['message' => __('app.success_action')]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
