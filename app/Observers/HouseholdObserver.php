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

    public function deleting(Household $household): void
    {
        $household->users()->detach();
    }
}
