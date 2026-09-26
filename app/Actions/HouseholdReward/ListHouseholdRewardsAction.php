<?php

namespace App\Actions\HouseholdReward;

use App\Models\Household;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class ListHouseholdRewardsAction
{
    use AsAction;

    /**
     * Lists the household's rewards, each with how hard it is to earn its points.
     *
     * @return Collection<int, Reward>
     */
    public function handle(Household $household): Collection
    {
        $calculate_difficulty = CalculateRewardDifficultyAction::make();
        $averages = $calculate_difficulty->householdAverages($household);

        return $household->rewards()
            ->with('user')
            ->get()
            ->each(fn (Reward $reward): Reward => $reward->forceFill([
                'is_editing' => $reward->isBeingEdited(),
                'difficulty' => $calculate_difficulty->evaluate($averages, $reward->points_cost),
            ]));
    }

    /**
     * @return array{household: Household, rewards: Collection<int, Reward>}
     */
    public function asController(Household $household): array
    {
        return ['household' => $household, 'rewards' => $this->handle($household)];
    }
}
