<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['task_instance_id', 'user_id', 'weekly_point_goal_id', 'penalty_points', 'task_offer_id', 'grace_granted_at', 'completed_at'])]
class TaskInstanceUser extends Model
{
    use LogsModelActivity;
    use SoftDeletes;

    /**
     * A grace day moves the due date of the claimed task instance by this many hours, see RequestTaskInstanceGraceDayAction.
     */
    public const int GRACE_HOURS = 24;

    /**
     * The grace days a member can use in a goal period of the household, see WeeklyPointGoal::weekStartsAt().
     */
    public const int GRACE_DAYS_PER_PERIOD = 1;

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'grace_granted_at' => 'datetime',
            'penalty_points' => 'int',
        ];
    }

    /**
     * Whether the claim is the user's own penalty for missing the weekly minimum points.
     * A penalty claim taken over through an offer keeps its weekly goal, so its task instance stays an extra, exclusive one,
     * but it is not the taker's penalty: the offerer paid the shortfall with the offered points, see AcceptTaskOfferAction.
     */
    public function isPenalty(): bool
    {
        return $this->weekly_point_goal_id !== null && $this->task_offer_id === null;
    }

    /**
     * The points paid for the user's share of the task: a penalty claim only pays the points above the shortfall it covers,
     * an older penalty claim without covered points pays nothing. A taken over penalty claim covers no shortfall.
     */
    public function payablePoints(int $points): int
    {
        if (! $this->weekly_point_goal_id) {
            return $points;
        }

        return max(0, $points - ($this->penalty_points ?? $points));
    }

    /**
     * The shortfall the claim covers, the offerer has to offer at least this much, see StoreTaskOfferAction.
     *
     * @param  int  $points  the points of the user's share of the task
     */
    public function coveredPenaltyPoints(int $points): int
    {
        return $this->isPenalty() ? min($points, $this->penalty_points ?? $points) : 0;
    }

    public function taskInstance(): BelongsTo
    {
        return $this->belongsTo(TaskInstance::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Set when the claim was assigned as a penalty for missing the weekly minimum points.
     */
    public function weeklyPointGoal(): BelongsTo
    {
        return $this->belongsTo(WeeklyPointGoal::class);
    }

    /**
     * The offer the claim was taken over through.
     */
    public function taskOffer(): BelongsTo
    {
        return $this->belongsTo(TaskOffer::class);
    }

    /**
     * The offers of this claim to the other members.
     */
    public function offers(): HasMany
    {
        return $this->hasMany(TaskOffer::class);
    }
}
