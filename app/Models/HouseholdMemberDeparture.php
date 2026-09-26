<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member who left or was removed from the household. Until an admin resolves it, the admins are asked
 * which of the tasks created by the former member to delete, see ResolveHouseholdMemberDepartureAction.
 */
#[Fillable(['household_id', 'user_id', 'removed_by', 'resolved_at', 'resolved_by'])]
class HouseholdMemberDeparture extends Model
{
    /**
     * A departure nobody decided about for this long is closed with every task kept, see CloseStaleMemberDeparturesAction.
     */
    public const int AUTO_CLOSE_DAYS = 30;

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    #[Scope]
    protected function unresolved(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * The still existing tasks of the household created by the former member.
     * A query and not a relation, because it depends on two columns and so cannot be eager loaded.
     *
     * @return Builder<Task>
     */
    public function createdTasks(): Builder
    {
        return Task::query()
            ->where('household_id', $this->household_id)
            ->where('created_by', $this->user_id);
    }
}
