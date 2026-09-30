<?php

namespace App\Actions\StickerAlbum;

use App\Models\Household;
use App\Models\Task;
use App\Models\TaskSticker;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class GetStickerAlbumAction
{
    use AsAction;

    /**
     * The user's sticker album of the household: a page for every task of the household (by category, then by name)
     * with a sticker slot for every milestone. The missing stickers of the completions made so far are unlocked first.
     * A sticker is new until the user marks it seen, see MarkStickersSeenAction.
     *
     * @return array{
     *     milestones: list<int>,
     *     collected: int,
     *     total: int,
     *     new_count: int,
     *     pages: list<array{
     *         task_id: int,
     *         task_name: string,
     *         is_recurring: bool,
     *         category: ?array{name: string, icon: ?string, color: ?string},
     *         completions: int,
     *         next_milestone: ?int,
     *         stickers: list<array{milestone: int, unlocked_at: ?string, is_new: bool}>,
     *     }>,
     * }
     */
    public function handle(User $user, Household $household): array
    {
        $completion_counts = SyncTaskStickersAction::run($user, $household);

        $stickers = TaskSticker::query()
            ->where('household_id', $household->id)
            ->where('user_id', $user->id)
            ->get()
            ->groupBy('task_id');

        $pages = $household->tasks()
            ->with('category')
            ->get()
            ->sortBy([
                fn (Task $a, Task $b): int => ($a->category->sort_order ?? PHP_INT_MAX) <=> ($b->category->sort_order ?? PHP_INT_MAX),
                fn (Task $a, Task $b): int => mb_strtolower($a->name) <=> mb_strtolower($b->name),
            ])
            ->map(fn (Task $task): array => $this->page($task, $completion_counts[$task->id] ?? 0, $stickers->get($task->id, new Collection)))
            ->values();

        $all_stickers = $pages->flatMap(fn (array $page): array => $page['stickers']);

        return [
            'milestones' => TaskSticker::MILESTONES,
            'collected' => $all_stickers->whereNotNull('unlocked_at')->count(),
            'total' => $all_stickers->count(),
            'new_count' => $all_stickers->where('is_new', true)->count(),
            'pages' => $pages->all(),
        ];
    }

    /**
     * @param  Collection<int, TaskSticker>  $task_stickers
     * @return array{task_id: int, task_name: string, is_recurring: bool, category: ?array{name: string, icon: ?string, color: ?string}, completions: int, next_milestone: ?int, stickers: list<array{milestone: int, unlocked_at: ?string, is_new: bool}>}
     */
    private function page(Task $task, int $completions, Collection $task_stickers): array
    {
        $task_stickers = $task_stickers->keyBy('milestone');

        return [
            'task_id' => $task->id,
            'task_name' => $task->name,
            'is_recurring' => $task->is_recurring,
            'category' => $task->category ? [
                'name' => $task->category->name,
                'icon' => $task->category->icon,
                'color' => $task->category->color,
            ] : null,
            'completions' => $completions,
            'next_milestone' => collect(TaskSticker::MILESTONES)->first(fn (int $milestone): bool => $milestone > $completions),
            'stickers' => collect(TaskSticker::MILESTONES)
                ->map(function (int $milestone) use ($task_stickers): array {
                    $task_sticker = $task_stickers->get($milestone);

                    return [
                        'milestone' => $milestone,
                        'unlocked_at' => $task_sticker?->unlocked_at->toIso8601String(),
                        'is_new' => $task_sticker !== null && $task_sticker->seen_at === null,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function asController(#[CurrentUser] User $user, Household $household): array
    {
        return $this->handle($user, $household);
    }
}
