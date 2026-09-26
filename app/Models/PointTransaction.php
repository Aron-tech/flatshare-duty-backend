<?php

namespace App\Models;

use App\Enums\PointTransactionType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['household_id', 'user_id', 'amount', 'balance_after', 'type', 'task_instance_id', 'task_offer_id', 'reward_redemption_id'])]
class PointTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'int',
            'balance_after' => 'int',
            'type' => PointTransactionType::class,
        ];
    }

    /**
     * The points the member earned in the household by doing tasks, see PointTransactionType::earnedTypes().
     */
    #[Scope]
    protected function earnedBy(Builder $query, int $household_id, int $user_id): void
    {
        $query->where('household_id', $household_id)
            ->where('user_id', $user_id)
            ->whereIn('type', PointTransactionType::earnedTypes());
    }

    /**
     * Created in the half-open period [from, until).
     */
    #[Scope]
    protected function createdBetween(Builder $query, CarbonInterface $from, CarbonInterface $until): void
    {
        $query->where('created_at', '>=', $from)->where('created_at', '<', $until);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taskInstance(): BelongsTo
    {
        return $this->belongsTo(TaskInstance::class)->withTrashed();
    }
}
