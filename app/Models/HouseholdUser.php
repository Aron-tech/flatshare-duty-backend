<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use App\Enums\RoleEnum;
use App\Observers\HouseholdUserObserver;
use App\Policies\HouseholdUserPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Custom pivot of the household members, so attaching and detaching members fires model events (activity log).
 */
#[Table('household_users', incrementing: true)]
#[Fillable(['household_id', 'user_id', 'role', 'points_balance'])]
#[ObservedBy([HouseholdUserObserver::class])]
#[UsePolicy(HouseholdUserPolicy::class)]
class HouseholdUser extends Pivot
{
    use LogsModelActivity {
        getActivitylogOptions as defaultActivitylogOptions;
    }

    /**
     * The points balance changes with every completed task, those are logged as point transactions.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->defaultActivitylogOptions()->dontLogIfAttributesChangedOnly(['points_balance']);
    }

    protected function casts(): array
    {
        return [
            'points_balance' => 'int',
            'role' => RoleEnum::class,
        ];
    }

    /**
     * The task completion points earned in the household's current goal period.
     */
    public function weeklyPoints(): int
    {
        $week_starts_at = WeeklyPointGoal::weekStartsAt(null, $this->household);

        return (int) PointTransaction::query()
            ->earnedBy($this->household_id, $this->user_id)
            ->createdBetween($week_starts_at, WeeklyPointGoal::weekEndsAt($week_starts_at, $this->household))
            ->sum('amount');
    }

    /**
     * The points that can be spent on rewards. The points earned towards the minimum of a week
     * that is not closed yet are held back, because closing the week deducts them, see CloseWeeklyPointGoalsAction.
     * The goal of the current week has to exist, see RecalculateWeeklyPointGoalsAction::currentGoals().
     */
    public function spendablePoints(): int
    {
        $held_back_points = WeeklyPointGoal::query()
            ->where('household_id', $this->household_id)
            ->where('user_id', $this->user_id)
            ->whereNull('closed_at')
            ->with('household')
            ->get()
            ->sum(fn (WeeklyPointGoal $goal) => $goal->settledPoints($goal->calculateEarnedPoints()));

        return max(0, $this->points_balance - $held_back_points);
    }

    /**
     * The grace days the member can still use in the household's current goal period, see TaskInstanceUser::GRACE_DAYS_PER_PERIOD.
     */
    public function graceDaysLeft(): int
    {
        $period_starts_at = WeeklyPointGoal::weekStartsAt(null, $this->household);

        $used = TaskInstanceUser::withTrashed()
            ->where('user_id', $this->user_id)
            ->whereHas('taskInstance', fn ($query) => $query->where('household_id', $this->household_id))
            ->where('grace_granted_at', '>=', $period_starts_at)
            ->where('grace_granted_at', '<', WeeklyPointGoal::weekEndsAt($period_starts_at, $this->household))
            ->count();

        return max(0, TaskInstanceUser::GRACE_DAYS_PER_PERIOD - $used);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
