<?php

namespace App\Models;

use App\Enums\PointTransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
