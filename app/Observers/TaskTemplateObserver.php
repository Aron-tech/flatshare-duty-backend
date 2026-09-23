<?php

namespace App\Observers;

use App\Models\TaskTemplate;

class TaskTemplateObserver
{
    public function creating(TaskTemplate $task_template): void
    {
        $task_template->calculateBasePoints();
    }
}
