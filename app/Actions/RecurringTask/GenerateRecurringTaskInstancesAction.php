<?php

namespace App\Actions\RecurringTask;

use App\Concerns\WritesCommandOutput;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Task;
use App\Models\TaskInstance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateRecurringTaskInstancesAction
{
    use AsAction;
    use WritesCommandOutput;

    public string $commandSignature = 'tasks:generate-instances {--output : Eredmény kiírása a konzolra}';

    public string $commandDescription = 'Létrehozza az ismétlődő feladatok esedékes új példányait, és kiosztja őket a felelősnek. A lejárt, nyitott példány nyitva marad.';

    /**
     * Creates the next instance of every recurring task whose recurrence period has elapsed
     * since its latest instance. The new instance is due at the end of its period and is assigned by the task's assignment mode.
     * An overdue instance that is still open is carried over instead: no new instance is created until it is done,
     * so the neglected task keeps its growing overdue bounty, see ReleaseOverdueTaskClaimsAction.
     * The instances created for weekly goal penalties are ignored, they are extra ones, even after the penalty claim was released.
     *
     * @return int the number of created task instances
     */
    public function handle(): int
    {
        $created = 0;

        Task::query()
            ->recurring()
            ->whereNotNull('recurrence_interval')
            ->whereNotNull('recurrence_unit')
            ->whereHas('household')
            ->with('household')
            ->lazyById()
            ->each(function (Task $task) use (&$created): void {
                $created += $this->generateFor($task) ? 1 : 0;
            });

        return $created;
    }

    public function generateFor(Task $task): ?TaskInstance
    {
        return DB::transaction(function () use ($task): ?TaskInstance {
            $latest = $task->taskInstances()
                ->whereDoesntHave('taskInstanceUsers', fn ($query) => $query->withTrashed()->whereNotNull('weekly_point_goal_id'))
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($latest && $task->addRecurrencePeriod($latest->created_at)->isFuture()) {
                return null;
            }

            if ($latest?->isOpen()) {
                if (! $latest->due_at) {
                    $latest->update(['due_at' => $task->addRecurrencePeriod($latest->created_at)]);
                }

                return null;
            }

            $task_instance = $task->taskInstances()->create([
                'household_id' => $task->household_id,
                'status' => TaskInstanceStatusEnum::PENDING,
                'due_at' => $task->addRecurrencePeriod(now()),
            ]);

            AssignTaskInstanceAction::make()->handle($task_instance->setRelation('task', $task));

            return $task_instance;
        });
    }

    public function asCommand(Command $command): void
    {
        $this->writeOutput($command, "Létrehozott példányok: {$this->handle()}");
    }
}
