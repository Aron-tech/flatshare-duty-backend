<?php

namespace App\Actions\Household;

use App\Enums\RoleEnum;
use App\Http\Requests\StoreHouseholdRequest;
use App\Models\Household;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class StoreHouseholdAction
{
    use AsAction;

    public function handle(User $user, array $data): Household
    {
        return DB::transaction(fn () => $user->households()->create([
            ...$data,
            'created_by' => $user->id,
        ], [
            'role' => RoleEnum::ADMIN,
        ]));
    }

    public function asController(StoreHouseholdRequest $request): JsonResponse
    {
        try {
            $household = $this->handle($request->user(), $request->validated());

            return response()->json(['household' => $household, 'message' => __('app.success_action')]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
