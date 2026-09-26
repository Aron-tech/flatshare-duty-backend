<?php

namespace App\Actions\HouseholdReward;

use App\Models\Household;
use App\Models\Reward;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Only the creator of the reward or an admin of the household can delete it.
 */
#[Authorize('delete', 'reward')]
class DeleteHouseholdRewardAction
{
    use AsAction;

    public function handle(Reward $reward): bool
    {
        return DB::transaction(fn (): bool => (bool) $reward->delete());
    }

    /**
     * @return array{message: string}
     */
    public function asController(Household $household, Reward $reward): array
    {
        $this->handle($reward);

        return ['message' => __('app.success_action')];
    }
}
