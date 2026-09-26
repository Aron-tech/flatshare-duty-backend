<?php

namespace App\Actions\HouseholdReward;

use App\Models\Household;
use App\Models\Reward;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('update', 'reward')]
class StopRewardEditingAction
{
    use AsAction;

    /**
     * Finishes the editing without saving, when the creator leaves the edit form.
     */
    public function handle(Reward $reward): Reward
    {
        DB::transaction(fn (): bool => $reward->update(['is_editing' => false, 'editing_started_at' => null]));

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
