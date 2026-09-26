<?php

namespace App\Actions\Household;

use App\Enums\RoleEnum;
use App\Http\Requests\StoreHouseholdRequest;
use App\Models\Household;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class StoreHouseholdAction
{
    use AsAction;

    /**
     * The creator becomes the admin of the new household.
     *
     * @param  array{name: string}  $data
     */
    public function handle(User $user, array $data): Household
    {
        return DB::transaction(fn (): Household => $user->households()->create([
            ...$data,
            'created_by' => $user->id,
        ], [
            'role' => RoleEnum::ADMIN,
        ]));
    }

    /**
     * @return array{household: Household, message: string}
     */
    public function asController(StoreHouseholdRequest $request, #[CurrentUser] User $user): array
    {
        return ['household' => $this->handle($user, $request->validated()), 'message' => __('app.success_action')];
    }
}
