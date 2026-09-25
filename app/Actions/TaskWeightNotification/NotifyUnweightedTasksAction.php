<?php

namespace App\Actions\TaskWeightNotification;

use App\Actions\PushToken\SendPushNotificationAction;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskWeightNotification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class NotifyUnweightedTasksAction
{
    use AsAction;

    public string $commandSignature = 'tasks:notify-unweighted';

    public string $commandDescription = 'Push értesítés a tagoknak a még nem súlyozott feladatokról (feladatonként egyszer).';

    private const int MAX_LISTED_TASKS = 5;

    /**
     * Tagonként és háztartásonként egy összesítő értesítést küld azokról a feladatokról,
     * amelyeknél a tag még nem adott meg súlyozást, és még nem kapott róluk értesítést.
     * Csak push tokennel rendelkező userek számítanak, így a token nélküliek a regisztráció után kapják meg.
     *
     * @return int az értesített (user, háztartás) párok száma
     */
    public function handle(): int
    {
        $pending = Task::query()
            ->join('household_users', 'household_users.household_id', '=', 'tasks.household_id')
            ->whereExists(fn (Builder $query) => $query->from('push_tokens')
                ->whereColumn('push_tokens.user_id', 'household_users.user_id'))
            ->whereNotExists(fn (Builder $query) => $query->from('task_user_weights')
                ->whereColumn('task_user_weights.task_id', 'tasks.id')
                ->whereColumn('task_user_weights.user_id', 'household_users.user_id'))
            ->whereNotExists(fn (Builder $query) => $query->from('task_weight_notifications')
                ->whereColumn('task_weight_notifications.task_id', 'tasks.id')
                ->whereColumn('task_weight_notifications.user_id', 'household_users.user_id'))
            ->orderBy('tasks.id')
            ->get(['tasks.id', 'tasks.name', 'tasks.household_id', 'household_users.user_id as notified_user_id'])
            ->groupBy(fn (Task $task): string => "{$task->notified_user_id}-{$task->household_id}");

        if ($pending->isEmpty()) {
            return 0;
        }

        $users = User::whereKey($pending->flatten()->pluck('notified_user_id')->unique())->get()->keyBy('id');
        $households = Household::whereKey($pending->flatten()->pluck('household_id')->unique())->get()->keyBy('id');

        foreach ($pending as $tasks) {
            $first = $tasks->first();
            $this->notify($users[$first->notified_user_id], $households[$first->household_id], $tasks);
        }

        return $pending->count();
    }

    /**
     * @param  Collection<int, Task>  $tasks
     */
    private function notify(User $user, Household $household, Collection $tasks): void
    {
        $locale = $user->language?->value;
        $names = $tasks->take(self::MAX_LISTED_TASKS)->pluck('name')->implode(', ');
        $remaining = $tasks->count() - self::MAX_LISTED_TASKS;
        $body = $remaining > 0
            ? __('app.task_weight_reminder_body_more', ['tasks' => $names, 'count' => $remaining], $locale)
            : __('app.task_weight_reminder_body', ['tasks' => $names], $locale);

        DB::transaction(function () use ($user, $household, $tasks, $body, $locale) {
            $now = now();
            TaskWeightNotification::insertOrIgnore($tasks->map(fn (Task $task): array => [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            SendPushNotificationAction::run(
                [$user->id],
                __('app.task_weight_reminder_title', ['household' => $household->name], $locale),
                $body,
                ['route' => '/chores', 'household_id' => $household->id],
            );
        });
    }

    public function asCommand(Command $command): void
    {
        $command->info("Értesítések: {$this->handle()}");
    }
}
