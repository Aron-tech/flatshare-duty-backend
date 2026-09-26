<?php

namespace App\Actions\HouseholdReward;

use App\Actions\GetHouseholdUserAction;
use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Enums\PointTransactionType;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Any member can redeem the reward except its creator, see RewardPolicy::redeem().
 */
#[Authorize('redeem', 'reward')]
class RedeemHouseholdRewardAction
{
    use AsAction;

    /**
     * Redeems the reward for the user from their spendable points, see HouseholdUser::spendablePoints().
     * A reward being edited cannot be redeemed.
     * A reward without a stock limit can be redeemed once a day by anyone in the household.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, Reward $reward): PointTransaction
    {
        $household_user = GetHouseholdUserAction::run($user, $household);

        RecalculateWeeklyPointGoalsAction::make()->currentGoals($household);

        return DB::transaction(function () use ($household_user, $reward): PointTransaction {
            $reward = Reward::query()->lockForUpdate()->findOrFail($reward->id);
            $household_user = HouseholdUser::query()->lockForUpdate()->findOrFail($household_user->id);

            if ($reward->isBeingEdited()) {
                throw new AuthorizationException(__('app.reward_being_edited'));
            }

            if (! $reward->is_active || ! $reward->isInStock()) {
                throw new AuthorizationException(__('app.reward_not_redeemable'));
            }

            if ($reward->stock_quantity === null && $reward->redemptions()->where('created_at', '>=', now(config('app.week_timezone'))->startOfDay()->utc())->exists()) {
                throw new AuthorizationException(__('app.reward_redeemed_today'));
            }

            if ($household_user->spendablePoints() < $reward->points_cost) {
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

    /**
     * @return array{points_balance: int, message: string}
     */
    public function asController(#[CurrentUser] User $user, Household $household, Reward $reward): array
    {
        return ['points_balance' => $this->handle($user, $household, $reward)->balance_after, 'message' => __('app.success_action')];
    }
}
