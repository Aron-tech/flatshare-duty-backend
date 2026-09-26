<?php

namespace App\Actions\HouseholdUser;

use App\Http\Requests\UpdateHouseholdUserRequest;
use App\Models\HouseholdUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * A member can change their own membership, anyone else's only an admin of the household, see HouseholdUserPolicy.
 */
#[Authorize('update', 'household_user')]
class UpdateHouseholdUserAction
{
    use AsAction;

    /**
     * @param  array{role?: string}  $data
     */
    public function handle(HouseholdUser $household_user, array $data): HouseholdUser
    {
        DB::transaction(fn (): bool => $household_user->update($data));

        return $household_user;
    }

    /**
     * @return array{household_user: HouseholdUser, message: string}
     */
    public function asController(UpdateHouseholdUserRequest $request, HouseholdUser $household_user): array
    {
        return ['household_user' => $this->handle($household_user, $request->validated()), 'message' => __('app.success_action')];
    }
}
