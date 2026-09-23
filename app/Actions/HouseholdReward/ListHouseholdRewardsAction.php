<?php

namespace App\Actions\HouseholdReward;

use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ListHouseholdRewardsAction
{
    use AsAction;

    public function handle(User $user, Household $household): Collection
    {
        if (!$user->households()->where('id', $household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        return $household->rewards()->with('user')->get();
    }

    public function asController(Request $request, Household $household): JsonResponse
    {
        try {
            $household_rewards = $this->handle($request->user(), $household);

            return response()->json(['household' => $household, 'rewards' => $household_rewards]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
