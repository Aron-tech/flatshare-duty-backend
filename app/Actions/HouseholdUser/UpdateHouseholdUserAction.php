<?php

namespace App\Actions\HouseholdUser;

use App\Http\Requests\UpdateHouseholdUserRequest;
use App\Models\HouseholdUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateHouseholdUserAction
{
    use AsAction;

    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, HouseholdUser $household_user, array $data): HouseholdUser
    {
        if ($user->id !== $household_user->user_id && ! $user->isAdminOf($household_user->household_id)) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        DB::transaction(fn () => $household_user->update($data));

        return $household_user;
    }

    public function asController(UpdateHouseholdUserRequest $request, HouseholdUser $household_user): JsonResponse
    {
        try {
            $household_user = $this->handle($request->user(), $household_user, $request->validated());

            return response()->json(['household_user' => $household_user, 'message' => __('app.success_action')]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
