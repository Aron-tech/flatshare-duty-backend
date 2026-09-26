<?php

namespace App\Policies;

use App\Concerns\GrantsPermission;
use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class HouseholdPolicy
{
    use GrantsPermission;

    /**
     * Any member can see and use the household: its tasks, rewards, statistics and QR code.
     */
    public function view(User $user, Household $household): Response
    {
        return $this->allowIf($user->isMemberOf($household));
    }

    /**
     * Renaming the household and changing its settings is for the admins.
     */
    public function update(User $user, Household $household): Response
    {
        return $this->allowIf($user->isAdminOf($household));
    }

    /**
     * The full member list with the roles and point balances is for the admins.
     */
    public function manageMembers(User $user, Household $household): Response
    {
        return $this->update($user, $household);
    }

    /**
     * Any member can leave, except the creator: the household would be left without its owner, they can delete it instead.
     */
    public function leave(User $user, Household $household): Response
    {
        if (! $user->isMemberOf($household)) {
            return $this->allowIf(false);
        }

        return $this->allowIf($user->id !== $household->created_by, 'app.household_owner_cannot_leave');
    }

    /**
     * Only the creator can delete the household.
     */
    public function delete(User $user, Household $household): Response
    {
        return $this->allowIf($user->id === $household->created_by);
    }
}
