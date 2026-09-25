<?php

namespace App\Actions\HouseholdReward;

use App\Enums\PointTransactionType;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class RedeemHouseholdRewardAction
{
    use AsAction;

    /**
     * Redeems the reward for the user from their points balance.
     * The creator cannot redeem their own reward, and a reward being edited cannot be redeemed.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, Reward $reward): PointTransaction
    {
        $household_user = $household->householdUsers()->where('user_id', $user->id)->first();
        if (! $household_user || $reward->household_id !== $household->id) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        if ($reward->user_id === $user->id) {
            throw new AuthorizationException(__('app.reward_own_not_redeemable'));
        }

        return DB::transaction(function () use ($household_user, $reward) {
            $reward = Reward::query()->lockForUpdate()->findOrFail($reward->id);
            $household_user = HouseholdUser::query()->lockForUpdate()->findOrFail($household_user->id);

            if ($reward->isBeingEdited()) {
                throw new AuthorizationException(__('app.reward_being_edited'));
            }

            if (! $reward->is_active || ! $reward->isInStock()) {
                throw new AuthorizationException(__('app.reward_not_redeemable'));
            }

            if ($household_user->points_balance < $reward->points_cost) {
                throw new AuthorizationException(__('app.reward_not_enough_points'));
            }

            if ($reward->stock_quantity !== null) {
                $reward->decrement('stock_quantity');
            }

            $household_user->decrement('points_balance', $reward->points_cost);

            $reward_redemption = RewardRedemption::create([
                'household_id' => $reward->household_id,
                'reward_id' => $reward->id,
                'user_id' => $household_user->user_id,
                'points_spent' => $reward->points_cost,
            ]);

            return PointTransaction::create([
                'household_id' => $household_user->household_id,
                'user_id' => $household_user->user_id,
                'amount' => $reward->points_cost,
                'balance_after' => $household_user->points_balance,
                'type' => PointTransactionType::REWARD_REDEMPTION,
                'reward_redemption_id' => $reward_redemption->id,
            ]);
        });
    }

    public function asController(Request $request, Household $household, Reward $reward): JsonResponse
    {
        try {
            $point_transaction = $this->handle($request->user(), $household, $reward);

            return response()->json([
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
