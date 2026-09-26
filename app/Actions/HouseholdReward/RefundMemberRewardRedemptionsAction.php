<?php

namespace App\Actions\HouseholdReward;

use App\Actions\PushToken\SendPushNotificationAction;
use App\Enums\PointTransactionType;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\RewardRedemption;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class RefundMemberRewardRedemptionsAction
{
    use AsAction;

    /**
     * Refunds the pending redemptions of the rewards created by a member leaving the household (the deleted rewards included),
     * because nobody will fulfill them anymore. Only the redeemers who are still members get the points back and a notification.
     *
     * @return int the number of refunded redemptions
     */
    public function handle(int $household_id, int $creator_id): int
    {
        return RewardRedemption::query()
            ->pending()
            ->where('household_id', $household_id)
            ->whereHas('reward', fn ($query) => $query->where('user_id', $creator_id))
            ->with(['reward', 'household'])
            ->get()
            ->filter(fn (RewardRedemption $reward_redemption): bool => $this->refund($reward_redemption))
            ->count();
    }

    private function refund(RewardRedemption $reward_redemption): bool
    {
        $household_user = HouseholdUser::query()
            ->where('household_id', $reward_redemption->household_id)
            ->where('user_id', $reward_redemption->user_id)
            ->lockForUpdate()
            ->first();
        if (! $household_user) {
            return false;
        }

        $household_user->increment('points_balance', $reward_redemption->points_spent);
        $reward_redemption->update(['refunded_at' => now()]);

        PointTransaction::create([
            'household_id' => $household_user->household_id,
            'user_id' => $household_user->user_id,
            'amount' => $reward_redemption->points_spent,
            'balance_after' => $household_user->points_balance,
            'type' => PointTransactionType::REWARD_REFUND,
            'reward_redemption_id' => $reward_redemption->id,
        ]);

        DB::afterCommit(fn () => $this->notify($reward_redemption));

        return true;
    }

    private function notify(RewardRedemption $reward_redemption): void
    {
        $locale = $reward_redemption->user->language?->value;

        SendPushNotificationAction::run(
            [$reward_redemption->user_id],
            __('app.reward_refunded_title', ['household' => $reward_redemption->household->name], $locale),
            __('app.reward_refunded_body', [
                'reward' => $reward_redemption->reward->name,
                'points' => $reward_redemption->points_spent,
            ], $locale),
            ['route' => '/rewards', 'household_id' => $reward_redemption->household_id],
        );
    }
}
