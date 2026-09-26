<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use App\Enums\TaskOfferStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A tag felajánlja a vállalását a többieknek, a felajánlott pontot zárolva (escrow), lásd StoreTaskOfferAction.
 */
#[Fillable(['household_id', 'task_instance_user_id', 'offered_by', 'target_user_id', 'accepted_by', 'points', 'penalty_points', 'status', 'accepted_at', 'resolved_at'])]
class TaskOffer extends Model
{
    use LogsModelActivity;

    protected function casts(): array
    {
        return [
            'points' => 'int',
            'penalty_points' => 'int',
            'status' => TaskOfferStatusEnum::class,
            'accepted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Whether the member can take the offer over: it is open, not their own, and addressed to anyone or to them.
     */
    public function isAcceptableBy(int $user_id): bool
    {
        return $this->status === TaskOfferStatusEnum::OPEN
            && $this->offered_by !== $user_id
            && (! $this->target_user_id || $this->target_user_id === $user_id);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * The offered claim, it is soft deleted once the offer is taken over.
     */
    public function taskInstanceUser(): BelongsTo
    {
        return $this->belongsTo(TaskInstanceUser::class)->withTrashed();
    }

    /**
     * The taker's claim created when the offer was accepted.
     */
    public function acceptedClaim(): HasOne
    {
        return $this->hasOne(TaskInstanceUser::class)->withTrashed();
    }

    public function offeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_by');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
