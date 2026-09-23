<?php

namespace App\Actions\Household;

use App\Http\Requests\RenameHouseholdRequest;
use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class RenameHouseholdAction
{
    use AsAction;

    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, array $data): Household
    {
        if (! $user->isAdminOf($household)) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        DB::transaction(fn () => $household->update($data));

        return $household;
    }

    public function asController(RenameHouseholdRequest $request, Household $household): JsonResponse
    {
        try {
            $household = $this->handle($request->user(), $household, $request->validated());

            return response()->json(['household' => $household, 'message' => __('app.success_action')]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
