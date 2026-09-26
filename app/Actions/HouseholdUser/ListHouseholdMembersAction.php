<?php

namespace App\Actions\HouseholdUser;

use App\Models\Household;
use App\Models\HouseholdUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class ListHouseholdMembersAction
{
    use AsAction;

    /**
     * Lists the members of the household for any member, with the name only,
     * so a task can be assigned to them. The full member list is admin only, see ListHouseholdUsersAction.
     *
     * @return Collection<int, array{user_id: int, name: string}>
     */
    public function handle(Household $household): Collection
    {
        return $household->householdUsers()->with('user')->orderBy('id')->get()
            ->map(fn (HouseholdUser $household_user): array => ['user_id' => $household_user->user_id, 'name' => $household_user->user->name])
            ->values();
    }

    /**
     * @return array{members: Collection<int, array{user_id: int, name: string}>}
     */
    public function asController(Household $household): array
    {
        return ['members' => $this->handle($household)];
    }
}
