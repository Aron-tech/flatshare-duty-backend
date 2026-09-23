<?php

namespace App\Actions\HouseholdUser;

use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

class ListHouseholdUsersAction
{
    use AsAction;

    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household): Collection
    {
        if (! $user->isAdminOf($household)) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        return $household->householdUsers()->with('user')->get();
    }

    public function asController(Request $request, Household $household): JsonResponse
    {
        try {
            $household_users = $this->handle($request->user(), $household);

            return response()->json(['household' => $household, 'household_users' => $household_users]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
