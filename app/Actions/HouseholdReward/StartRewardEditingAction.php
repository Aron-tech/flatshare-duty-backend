<?php

namespace App\Actions\HouseholdReward;

use App\Models\Household;
use App\Models\Reward;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('update', 'reward')]
class StartRewardEditingAction
{
    use AsAction;

    /**
     * Marks the reward as being edited by its creator, so nobody can redeem it meanwhile.
     * Calling it again restarts the editing timeout.
     */
    public function handle(Reward $reward): Reward
    {
        DB::transaction(fn (): bool => $reward->update(['is_editing' => true, 'editing_started_at' => now()]));

        return $reward;
    }

    /**
     * @return array{reward: Reward}
     */
    public function asController(Household $household, Reward $reward): array
    {
        return ['reward' => $this->handle($reward)];
    }
}
