<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['household_id', 'user_id', 'name', 'description', 'points_cost', 'stock_quantity', 'is_active', 'is_editing', 'editing_started_at'])]
class Reward extends Model
{
    use LogsModelActivity {
        getActivitylogOptions as defaultActivitylogOptions;
    }
    use SoftDeletes;

    /**
     * An editing left open longer than this is released, so the reward can be redeemed again.
     */
    public const int EDITING_TIMEOUT_MINUTES = 15;

    /**
     * The editing lock is not a change of the reward.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->defaultActivitylogOptions()->logExcept(['is_editing', 'editing_started_at']);
    }

    protected function casts(): array
    {
        return [
            'points_cost' => 'int',
            'stock_quantity' => 'int',
            'is_active' => 'boolean',
            'is_editing' => 'boolean',
            'editing_started_at' => 'datetime',
        ];
    }

    /**
     * Only the creator of the reward can edit it.
     */
    public function canBeEditedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * The creator of the reward and the admins of its household can delete it.
     */
    public function canBeDeletedBy(User $user): bool
    {
        return $this->canBeEditedBy($user) || $user->isAdminOf($this->household_id);
    }

    /**
     * A stale editing counts as finished even before the scheduler releases it.
     */
    public function isBeingEdited(): bool
    {
        return $this->is_editing && $this->editing_started_at?->gt(now()->subMinutes(self::EDITING_TIMEOUT_MINUTES));
    }

    public function isInStock(): bool
    {
        return $this->stock_quantity === null || $this->stock_quantity > 0;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }
}
