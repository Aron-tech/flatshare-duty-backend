<?php

namespace App\Actions\StickerAlbum;

use App\Models\Household;
use App\Models\TaskSticker;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class MarkStickersSeenAction
{
    use AsAction;

    /**
     * Marks the user's new stickers of the household seen, so the album no longer highlights them.
     *
     * @param  ?int  $task_id  only the stickers of this task
     * @return int the number of stickers marked seen
     */
    public function handle(User $user, Household $household, ?int $task_id = null): int
    {
        return TaskSticker::query()
            ->where('household_id', $household->id)
            ->where('user_id', $user->id)
            ->when($task_id, fn ($query) => $query->where('task_id', $task_id))
            ->whereNull('seen_at')
            ->update(['seen_at' => now()]);
    }

    /**
     * @return array{marked: int}
     */
    public function asController(Request $request, #[CurrentUser] User $user, Household $household): array
    {
        $request->validate([
            'task_id' => ['nullable', 'integer'],
        ]);

        return ['marked' => $this->handle($user, $household, $request->filled('task_id') ? $request->integer('task_id') : null)];
    }
}
