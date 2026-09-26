<?php

namespace App\Policies;

use App\Concerns\GrantsPermission;
use App\Models\RewardRedemption;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RewardRedemptionPolicy
{
    use GrantsPermission;

    /**
     * The creator of the reward marks it fulfilled when they delivered it, or the redeemer when they received it, while they are members.
     */
    public function fulfill(User $user, RewardRedemption $reward_redemption): Response
    {
        $is_involved = in_array($user->id, [$reward_redemption->user_id, $reward_redemption->reward?->user_id], true);

        return $this->allowIf($is_involved && $user->isMemberOf($reward_redemption->household_id));
    }
}
