<?php

namespace App\Actions\HouseholdUser;

use App\Models\Household;
use App\Models\HouseholdUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('manageMembers', 'household')]
class ListHouseholdUsersAction
{
    use AsAction;

    /**
     * @return Collection<int, HouseholdUser>
     */
    public function handle(Household $household): Collection
    {
        return $household->householdUsers()->with('user')->get();
    }

    /**
     * @return array{household: Household, household_users: Collection<int, HouseholdUser>}
     */
    public function asController(Household $household): array
    {
        return ['household' => $household, 'household_users' => $this->handle($household)];
    }
}
