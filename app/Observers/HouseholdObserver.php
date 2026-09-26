<?php

namespace App\Observers;

use App\Models\Household;
use Random\RandomException;

class HouseholdObserver
{
    /**
     * @throws RandomException
     */
    public function creating(Household $household): void
    {
        if (empty($household->join_code)) {
            $household->join_code = Household::generateUniqueJoinCode();
        }
    }

    /**
     * The members are removed after the household is trashed, so HouseholdUserObserver does not treat it as members leaving.
     */
    public function deleted(Household $household): void
    {
        $household->users()->detach();
    }
}
