<?php

namespace App\Actions\Household;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ListHouseholdsAction
{
    use AsAction;

    public function handle(User $user): Collection
    {
        return $user->households;
    }

    public function asController(Request $request): JsonResponse
    {
        try {
            $households = $this->handle($request->user());

            return response()->json(['households' => $households]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
