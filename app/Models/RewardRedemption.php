<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use App\Policies\RewardRedemptionPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A redeemed reward is pending until its creator (or the redeemer) marks it fulfilled.
 * A pending one is refunded when the creator of the reward leaves the household, see RefundMemberRewardRedemptionsAction.
 */
#[Fillable(['household_id', 'reward_id', 'user_id', 'points_spent', 'fulfilled_at', 'refunded_at'])]
#[UsePolicy(RewardRedemptionPolicy::class)]
class RewardRedemption extends Model
{
    use LogsModelActivity;

    protected function casts(): array
    {
        return [
            'points_spent' => 'int',
            'fulfilled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('fulfilled_at')->whereNull('refunded_at');
    }

    public function isPending(): bool
    {
        return $this->fulfilled_at === null && $this->refunded_at === null;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
