<?php

namespace App\Observers;

use App\Models\Task;
use Illuminate\Support\Facades\Auth;

class TaskObserver
{
    public function creating(Task $task): void
    {
        if (!$task->created_by && Auth::check()) {
            $task->created_by = Auth::id();
        }
        $task->calculateBasePoints();
    }
}
