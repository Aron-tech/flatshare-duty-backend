<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use App\Enums\TaskInstanceStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * @property ?int $points Calculated for the listing user, see CalculateTaskPointsAction.
 */
#[Fillable(['task_id', 'household_id', 'status', 'due_at', 'completed_at'])]
class TaskInstance extends Model
{
    use LogsModelActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => TaskInstanceStatusEnum::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Pending and not completed, so it can still be claimed and completed.
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', TaskInstanceStatusEnum::PENDING)->whereNull('completed_at');
    }

    public function isOpen(): bool
    {
        return $this->status === TaskInstanceStatusEnum::PENDING && ! $this->completed_at;
    }

    public function isOverdue(): bool
    {
        return (bool) $this->due_at?->isPast();
    }

    /**
     * Includes the deleted task, so the history of its completed instances stays readable.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function taskInstanceUsers(): HasMany
    {
        return $this->hasMany(TaskInstanceUser::class);
    }

    /**
     * Whether another member can join the claimers: there is a free place, it is not a member's penalty task,
     * and nobody has completed their part yet, unless the instance is overdue and the part of a released claim has to be taken over.
     * The claimers share the points, see CalculateTaskPointsAction, so a late joiner would inflate the task's points.
     *
     * @param  Collection<int, TaskInstanceUser>|null  $claims  the claims of the instance, queried when not given
     */
    public function isJoinable(?Collection $claims = null): bool
    {
        $claims ??= $this->taskInstanceUsers()->get();
        if ($claims->count() >= $this->task->max_user || $claims->whereNotNull('weekly_point_goal_id')->isNotEmpty()) {
            return false;
        }

        return $this->isOverdue() || $claims->whereNotNull('completed_at')->isEmpty();
    }

    /**
     * Claims the instance for the user. A claim released at the due date is replaced,
     * the release stays recorded as a missed task penalty, see ReleaseOverdueTaskClaimsAction.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function claimFor(int $user_id, array $attributes = []): TaskInstanceUser
    {
        $this->taskInstanceUsers()->onlyTrashed()->where('user_id', $user_id)->forceDelete();

        return $this->taskInstanceUsers()->create(['user_id' => $user_id, ...$attributes]);
    }

    public function userWeights(): HasMany
    {
        return $this->hasMany(TaskUserWeight::class, 'task_id', 'task_id');
    }
}
