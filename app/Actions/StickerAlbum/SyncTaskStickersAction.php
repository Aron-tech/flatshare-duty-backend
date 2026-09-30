<?php

namespace App\Actions\StickerAlbum;

use App\Models\Household;
use App\Models\Task;
use App\Models\TaskInstanceUser;
use App\Models\TaskSticker;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncTaskStickersAction
{
    use AsAction;

    /**
     * Unlocks the stickers of the milestones (TaskSticker::MILESTONES) the user has reached with the tasks of the household.
     * Every completed claim of the user counts, a penalty or taken over one too, so the completions made before the album existed
     * unlock their stickers on the first sync. A sticker is unlocked at the time of the completion that reached its milestone.
     *
     * @param  ?Task  $task  only this task, otherwise every task of the household
     * @return array<int, int> the completion count of the user by task id
     */
    public function handle(User $user, Household $household, ?Task $task = null): array
    {
        $completions = $this->completionTimes($user, $household, $task);

        $unlocked = TaskSticker::query()
            ->where('household_id', $household->id)
            ->where('user_id', $user->id)
            ->when($task, fn ($query) => $query->where('task_id', $task->id))
            ->get(['task_id', 'milestone'])
            ->map(fn (TaskSticker $task_sticker): string => "{$task_sticker->task_id}-{$task_sticker->milestone}")
            ->flip();

        $now = now();
        $new_stickers = [];
        foreach ($completions as $task_id => $completed_at) {
            foreach (TaskSticker::MILESTONES as $milestone) {
                if ($completed_at->count() < $milestone || $unlocked->has("{$task_id}-{$milestone}")) {
                    continue;
                }

                $new_stickers[] = [
                    'household_id' => $household->id,
                    'user_id' => $user->id,
                    'task_id' => $task_id,
                    'milestone' => $milestone,
                    'unlocked_at' => $completed_at[$milestone - 1],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($new_stickers) {
            TaskSticker::query()->insertOrIgnore($new_stickers);
        }

        return $completions->map->count()->all();
    }

    /**
     * The sticker the user has just unlocked with the completion of the task, when the completion reached a milestone.
     *
     * @return ?array{task_id: int, task_name: string, category_icon: ?string, category_color: ?string, milestone: int}
     */
    public function reachedSticker(User $user, Task $task): ?array
    {
        $completion_count = TaskInstanceUser::query()
            ->where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->whereHas('taskInstance', fn ($query) => $query->where('task_id', $task->id))
            ->count();

        $task_sticker = TaskSticker::query()
            ->where('user_id', $user->id)
            ->where('task_id', $task->id)
            ->where('milestone', $completion_count)
            ->whereNull('seen_at')
            ->first();

        if (! $task_sticker) {
            return null;
        }

        return [
            'task_id' => $task->id,
            'task_name' => $task->name,
            'category_icon' => $task->category?->icon,
            'category_color' => $task->category?->color,
            'milestone' => $task_sticker->milestone,
        ];
    }

    /**
     * The completion times of the user's claims, oldest first, grouped by task id.
     *
     * @return Collection<int, Collection<int, CarbonInterface>>
     */
    private function completionTimes(User $user, Household $household, ?Task $task): Collection
    {
        return TaskInstanceUser::query()
            ->join('task_instances', 'task_instances.id', '=', 'task_instance_users.task_instance_id')
            ->where('task_instances.household_id', $household->id)
            ->when($task, fn ($query) => $query->where('task_instances.task_id', $task->id))
            ->where('task_instance_users.user_id', $user->id)
            ->whereNotNull('task_instance_users.completed_at')
            ->orderBy('task_instance_users.completed_at')
            ->orderBy('task_instance_users.id')
            ->get(['task_instances.task_id', 'task_instance_users.completed_at'])
            ->groupBy('task_id')
            ->map(fn (Collection $claims): Collection => $claims->pluck('completed_at')->values());
    }
}
