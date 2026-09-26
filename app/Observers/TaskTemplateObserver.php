<?php

namespace App\Observers;

use App\Models\TaskTemplate;

class TaskTemplateObserver
{
    public function creating(TaskTemplate $task_template): void
    {
        $task_template->calculateBasePoints();
    }

    public function saved(TaskTemplate $task_template): void
    {
        TaskTemplate::invalidateCache();
    }

    public function deleted(TaskTemplate $task_template): void
    {
        TaskTemplate::invalidateCache();
    }
}
