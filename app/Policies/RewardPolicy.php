<?php

namespace App\Policies;

use App\Concerns\GrantsPermission;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RewardPolicy
{
    use GrantsPermission;

    /**
     * Only the creator of the reward can edit it, while they are still a member of its household.
     */
    public function update(User $user, Reward $reward): Response
    {
        return $this->allowIf($this->isCreatorMember($user, $reward));
    }

    /**
     * The creator of the reward, while they are still a member, and the admins of its household can delete it.
     */
    public function delete(User $user, Reward $reward): Response
    {
        return $this->allowIf($this->isCreatorMember($user, $reward) || $user->isAdminOf($reward->household_id));
    }

    /**
     * Any member can redeem the reward, except its creator.
     */
    public function redeem(User $user, Reward $reward): Response
    {
        if (! $user->isMemberOf($reward->household_id)) {
            return $this->allowIf(false);
        }

        return $this->allowIf($reward->user_id !== $user->id, 'app.reward_own_not_redeemable');
    }

    private function isCreatorMember(User $user, Reward $reward): bool
    {
        return $reward->user_id === $user->id && $user->isMemberOf($reward->household_id);
    }
}
