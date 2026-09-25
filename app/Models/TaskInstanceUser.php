<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['task_instance_id', 'user_id', 'weekly_point_goal_id', 'completed_at'])]
class TaskInstanceUser extends Model
{
    use LogsModelActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
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
}
