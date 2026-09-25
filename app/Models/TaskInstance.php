<?php

namespace App\Models;

use App\Enums\TaskInstanceStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ?int $points Calculated for the current user, see CalculateTaskPointsAction.
 */
#[Fillable(['task_id', 'household_id', 'status', 'due_at', 'completed_at'])]
class TaskInstance extends Model
{
    protected function casts(): array
    {
        return [
            'status' => TaskInstanceStatusEnum::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function taskInstanceUsers(): HasMany
    {
        return $this->hasMany(TaskInstanceUser::class);
    }

    public function userWeights(): HasMany
    {
        return $this->hasMany(TaskUserWeight::class, 'task_id', 'task_id');
    }
}
