<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sticker of the user's sticker album: the user completed the task of the household this many times (milestone).
 * unlocked_at is the time of the completion that reached the milestone, seen_at is set once the user saw it in the album.
 */
#[Fillable(['household_id', 'user_id', 'task_id', 'milestone', 'unlocked_at', 'seen_at'])]
class TaskSticker extends Model
{
    /**
     * The completion counts of a task that unlock a sticker.
     *
     * @var list<int>
     */
    public const array MILESTONES = [10, 25, 50, 100];

    protected function casts(): array
    {
        return [
            'milestone' => 'int',
            'unlocked_at' => 'datetime',
            'seen_at' => 'datetime',
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }
}
