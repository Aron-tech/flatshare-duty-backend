<?php

namespace App\Models;

use App\Enums\PointTransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tag heti minimum pontszáma a háztartásban, a hét lezárásakor a szerzett ponttal és a hiánnyal együtt.
 */
#[Fillable(['household_id', 'user_id', 'week_starts_at', 'target_points', 'earned_points', 'shortfall_points', 'reminded_at', 'closed_at'])]
class WeeklyPointGoal extends Model
{
    protected function casts(): array
    {
        return [
            'week_starts_at' => 'immutable_date',
            'target_points' => 'int',
            'earned_points' => 'int',
            'shortfall_points' => 'int',
            'reminded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * The task completion points the user earned in the household during the goal's week.
     */
    public function calculateEarnedPoints(): int
    {
        return (int) PointTransaction::query()
            ->where('household_id', $this->household_id)
            ->where('user_id', $this->user_id)
            ->where('type', PointTransactionType::TASK_COMPLETION)
            ->where('created_at', '>=', $this->week_starts_at->startOfDay())
            ->where('created_at', '<', $this->week_starts_at->startOfDay()->addWeek())
            ->sum('amount');
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A hiány miatt kiosztott büntető feladatok.
     */
    public function penaltyTaskInstanceUsers(): HasMany
    {
        return $this->hasMany(TaskInstanceUser::class);
    }
}
