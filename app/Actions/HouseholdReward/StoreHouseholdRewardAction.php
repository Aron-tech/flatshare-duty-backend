<?php

namespace App\Actions\HouseholdReward;

use App\Http\Requests\StoreHouseholdRewardRequest;
use App\Models\Household;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Every member of the household can create a reward.
 */
#[Authorize('view', 'household')]
class StoreHouseholdRewardAction
{
    use AsAction;

    /**
     * @param  array{name: string, description?: ?string, points_cost: int, stock_quantity?: ?int, is_active?: ?bool}  $data
     */
    public function handle(User $user, Household $household, array $data): Reward
    {
        return DB::transaction(fn (): Reward => $household->rewards()->create([
            ...$data,
            'is_active' => $data['is_active'] ?? true,
            'user_id' => $user->id,
        ]));
    }

    /**
     * @return array{reward: Reward, difficulty: array<string, mixed>, message: string}
     */
    public function asController(StoreHouseholdRewardRequest $request, #[CurrentUser] User $user, Household $household): array
    {
        $reward = $this->handle($user, $household, $request->validated());

        return [
            'reward' => $reward->load('user'),
            'difficulty' => CalculateRewardDifficultyAction::run($household, $reward->points_cost),
            'message' => __('app.success_action'),
        ];
    }
}
