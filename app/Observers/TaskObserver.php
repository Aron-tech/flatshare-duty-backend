<?php

namespace App\Observers;

use App\Enums\TaskInstanceStatusEnum;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;

class TaskObserver
{
    public function creating(Task $task): void
    {
        if (! $task->created_by && Auth::check()) {
            $task->created_by = Auth::id();
        }
        $task->calculateBasePoints();
    }

    public function created(Task $task): void
    {
        $task->taskInstances()->create([
            'household_id' => $task->household_id,
            'status' => TaskInstanceStatusEnum::PENDING,
            'due_at' => $task->addRecurrencePeriod(now()),
        ]);
    }
}
