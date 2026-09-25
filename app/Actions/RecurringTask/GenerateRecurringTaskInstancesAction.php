<?php

namespace App\Actions\RecurringTask;

use App\Enums\TaskInstanceStatusEnum;
use App\Models\Task;
use App\Models\TaskInstance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateRecurringTaskInstancesAction
{
    use AsAction;

    public string $commandSignature = 'tasks:generate-instances';

    public string $commandDescription = 'Létrehozza az ismétlődő feladatok esedékes új példányait, és kiosztja őket a felelősnek.';

    /**
     * Creates the next instance of every recurring task whose recurrence period has elapsed
     * since its latest instance. The previous instance, if still open, expires.
     * The new instance is due at the end of its period and is assigned by the task's assignment mode.
     *
     * @return int the number of created task instances
     */
    public function handle(): int
    {
        $created = 0;

        Task::query()
            ->where('is_recurring', true)
            ->whereNotNull('recurrence_interval')
            ->whereNotNull('recurrence_unit')
            ->with('household')
            ->lazyById()
            ->each(function (Task $task) use (&$created) {
                $created += $this->generateFor($task) ? 1 : 0;
            });

        return $created;
    }

    public function generateFor(Task $task): ?TaskInstance
    {
        return DB::transaction(function () use ($task) {
            $latest = $task->taskInstances()->latest('id')->lockForUpdate()->first();

            if ($latest && $this->addPeriod($task, $latest->created_at)->isFuture()) {
                return null;
            }

            if ($latest?->status === TaskInstanceStatusEnum::PENDING) {
                $latest->update(['status' => TaskInstanceStatusEnum::EXPIRED]);
            }

            $task_instance = $task->taskInstances()->create([
                'household_id' => $task->household_id,
                'status' => TaskInstanceStatusEnum::PENDING,
                'due_at' => $this->addPeriod($task, now()),
            ]);

            AssignTaskInstanceAction::make()->handle($task_instance->setRelation('task', $task));

            return $task_instance;
        });
    }

    private function addPeriod(Task $task, CarbonInterface $from): CarbonImmutable
    {
        return CarbonImmutable::instance($from)->add($task->recurrence_unit->value, $task->recurrence_interval);
    }

    public function asCommand(Command $command): void
    {
        $command->info("Létrehozott példányok: {$this->handle()}");
    }
}
