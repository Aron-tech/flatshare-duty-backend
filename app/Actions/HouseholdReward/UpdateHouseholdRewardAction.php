<?php

namespace App\Actions\HouseholdReward;

use App\Http\Requests\UpdateHouseholdRewardRequest;
use App\Models\Household;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateHouseholdRewardAction
{
    use AsAction;

    /**
     * Only the creator of the reward can update it, saving also finishes the editing.
     *
     * @param  array{name?: string, description?: ?string, points_cost?: int, stock_quantity?: ?int, is_active?: bool}  $data
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, Reward $reward, array $data): Reward
    {
        if ($reward->household_id !== $household->id || ! $reward->canBeEditedBy($user)) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        DB::transaction(fn () => $reward->update([...$data, 'is_editing' => false, 'editing_started_at' => null]));

        return $reward;
    }

    public function asController(UpdateHouseholdRewardRequest $request, Household $household, Reward $reward): JsonResponse
    {
        try {
            $reward = $this->handle($request->user(), $household, $reward, $request->validated());

            return response()->json([
                'reward' => $reward->load('user'),
                'difficulty' => CalculateRewardDifficultyAction::make()->calculate($household, $reward->points_cost),
                'message' => __('app.success_action'),
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
