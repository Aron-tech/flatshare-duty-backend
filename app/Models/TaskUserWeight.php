<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use App\Enums\TaskUserWeightEnum;
use App\Observers\WeeklyPointGoalObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['household_id', 'user_id', 'task_id', 'weight'])]
#[ObservedBy([WeeklyPointGoalObserver::class])]
class TaskUserWeight extends Model
{
    use LogsModelActivity;

    protected function casts(): array
    {
        return [
            'weight' => TaskUserWeightEnum::class,
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
        return $this->belongsTo(Task::class);
    }
}
