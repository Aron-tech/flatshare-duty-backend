<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['household_id', 'reward_id', 'user_id', 'points_spent'])]
class RewardRedemption extends Model
{
    use LogsModelActivity;

    protected function casts(): array
    {
        return [
            'points_spent' => 'int',
        ];
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
