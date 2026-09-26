<?php

namespace App\Policies;

use App\Concerns\GrantsPermission;
use App\Models\HouseholdUser;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class HouseholdUserPolicy
{
    use GrantsPermission;

    /**
     * A member can change their own membership, anyone else's only an admin of the household.
     * The creator's membership is locked: they always stay an admin, so the household always has one.
     */
    public function update(User $user, HouseholdUser $household_user): Response
    {
        if ($household_user->user_id !== $user->id && ! $user->isAdminOf($household_user->household_id)) {
            return $this->allowIf(false);
        }

        return $this->allowIf($household_user->user_id !== $household_user->household->created_by, 'app.household_owner_membership_locked');
    }

    /**
     * A member can remove themselves, anyone else can only be removed by an admin of the household. The creator cannot be removed.
     */
    public function delete(User $user, HouseholdUser $household_user): Response
    {
        return $this->update($user, $household_user);
    }
}
