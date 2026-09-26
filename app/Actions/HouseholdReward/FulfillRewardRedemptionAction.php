<?php

namespace App\Actions\HouseholdReward;

use App\Models\Household;
use App\Models\RewardRedemption;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The creator of the reward or the redeemer can mark it fulfilled, see RewardRedemptionPolicy.
 */
#[Authorize('fulfill', 'reward_redemption')]
class FulfillRewardRedemptionAction
{
    use AsAction;

    /**
     * @throws AuthorizationException when it was fulfilled or refunded meanwhile
     */
    public function handle(RewardRedemption $reward_redemption): RewardRedemption
    {
        return DB::transaction(function () use ($reward_redemption): RewardRedemption {
            $reward_redemption = RewardRedemption::query()->lockForUpdate()->findOrFail($reward_redemption->id);
            if (! $reward_redemption->isPending()) {
                throw new AuthorizationException(__('app.reward_redemption_not_pending'));
            }

            $reward_redemption->update(['fulfilled_at' => now()]);

            return $reward_redemption;
        });
    }

    /**
     * @return array{reward_redemption: RewardRedemption, message: string}
     */
    public function asController(Household $household, RewardRedemption $reward_redemption): array
    {
        return ['reward_redemption' => $this->handle($reward_redemption), 'message' => __('app.success_action')];
    }
}
