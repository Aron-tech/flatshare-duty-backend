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

class StopRewardEditingAction
{
    use AsAction;

    /**
     * Finishes the editing without saving, when the creator leaves the edit form.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, Reward $reward): Reward
    {
        if ($reward->household_id !== $household->id || ! $reward->canBeEditedBy($user)) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        DB::transaction(fn () => $reward->update(['is_editing' => false, 'editing_started_at' => null]));

        return $reward;
    }

    public function asController(Request $request, Household $household, Reward $reward): JsonResponse
    {
        try {
            return response()->json(['reward' => $this->handle($request->user(), $household, $reward)]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
