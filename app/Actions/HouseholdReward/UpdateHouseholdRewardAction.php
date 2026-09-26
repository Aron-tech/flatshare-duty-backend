<?php

namespace App\Actions\HouseholdReward;

use App\Http\Requests\UpdateHouseholdRewardRequest;
use App\Models\Household;
use App\Models\Reward;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Only the creator of the reward can update it.
 */
#[Authorize('update', 'reward')]
class UpdateHouseholdRewardAction
{
    use AsAction;

    /**
     * Saving also finishes the editing.
     *
     * @param  array{name?: string, description?: ?string, points_cost?: int, stock_quantity?: ?int, is_active?: bool}  $data
     */
    public function handle(Reward $reward, array $data): Reward
    {
        DB::transaction(fn (): bool => $reward->update([...$data, 'is_editing' => false, 'editing_started_at' => null]));

        return $reward;
    }

    /**
     * @return array{reward: Reward, difficulty: array<string, mixed>, message: string}
     */
    public function asController(UpdateHouseholdRewardRequest $request, Household $household, Reward $reward): array
    {
        $reward = $this->handle($reward, $request->validated());

        return [
            'reward' => $reward->load('user'),
            'difficulty' => CalculateRewardDifficultyAction::run($household, $reward->points_cost),
            'message' => __('app.success_action'),
        ];
    }
}
