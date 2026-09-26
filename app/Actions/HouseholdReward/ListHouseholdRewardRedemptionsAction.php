<?php

namespace App\Actions\HouseholdReward;

use App\Models\Household;
use App\Models\RewardRedemption;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class ListHouseholdRewardRedemptionsAction
{
    use AsAction;

    /**
     * The user's pending redemptions: the ones of their rewards they have to fulfill (redeemed by current members),
     * and the ones they redeemed and are waiting for.
     *
     * @return array{to_fulfill: Collection<int, RewardRedemption>, waiting: Collection<int, RewardRedemption>}
     */
    public function handle(User $user, Household $household): array
    {
        $pending = $household->rewardRedemptions()
            ->pending()
            ->with(['reward', 'user'])
            ->oldest()
            ->get();

        $member_ids = $household->householdUsers()->pluck('user_id');

        return [
            'to_fulfill' => $pending
                ->filter(fn (RewardRedemption $reward_redemption): bool => $reward_redemption->reward?->user_id === $user->id && $member_ids->contains($reward_redemption->user_id))
                ->values(),
            'waiting' => $pending
                ->filter(fn (RewardRedemption $reward_redemption): bool => $reward_redemption->user_id === $user->id)
                ->values(),
        ];
    }

    /**
     * @return array{to_fulfill: Collection<int, RewardRedemption>, waiting: Collection<int, RewardRedemption>}
     */
    public function asController(#[CurrentUser] User $user, Household $household): array
    {
        return $this->handle($user, $household);
    }
}
