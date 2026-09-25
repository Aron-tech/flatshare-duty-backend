<?php

namespace App\Actions\HouseholdUser;

use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ListHouseholdMembersAction
{
    use AsAction;

    /**
     * Lists the members of the household for any member, with the name only,
     * so a task can be assigned to them. The full member list is admin only.
     *
     * @return Collection<int, array{user_id: int, name: string}>
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household): Collection
    {
        if (! $user->households()->whereKey($household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        return $household->householdUsers()->with('user')->orderBy('id')->get()
            ->map(fn (HouseholdUser $household_user) => ['user_id' => $household_user->user_id, 'name' => $household_user->user->name]);
    }

    public function asController(Request $request, Household $household): JsonResponse
    {
        try {
            return response()->json(['members' => $this->handle($request->user(), $household)->values()]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
