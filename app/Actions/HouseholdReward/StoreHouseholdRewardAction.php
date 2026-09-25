<?php

namespace App\Actions\HouseholdReward;

use App\Http\Requests\StoreHouseholdRewardRequest;
use App\Models\Household;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class StoreHouseholdRewardAction
{
    use AsAction;

    /**
     * Every member of the household can create a reward.
     *
     * @param  array{name: string, description?: ?string, points_cost: int, stock_quantity?: ?int, is_active?: ?bool}  $data
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, array $data): Reward
    {
        if (! $user->households()->whereKey($household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        return DB::transaction(fn () => $household->rewards()->create([
            ...$data,
            'is_active' => $data['is_active'] ?? true,
            'user_id' => $user->id,
        ]));
    }

    public function asController(StoreHouseholdRewardRequest $request, Household $household): JsonResponse
    {
        try {
            $reward = $this->handle($request->user(), $household, $request->validated());

            return response()->json([
                'reward' => $reward->load('user'),
                'difficulty' => CalculateRewardDifficultyAction::make()->calculate($household, $reward->points_cost),
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
