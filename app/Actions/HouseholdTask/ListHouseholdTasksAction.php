<?php

namespace App\Actions\HouseholdTask;

use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

class ListHouseholdTasksAction
{
    use AsAction;

    /**
     * Lists the tasks of the household for any member, with the user's own weight and the rotation members of each task.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household): Collection
    {
        if (! $user->households()->whereKey($household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        return $household->tasks()
            ->with([
                'category',
                'rotations',
                'userWeights' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->orderBy('name')
            ->get()
            ->groupBy(fn ($task) => $task->category?->name ?? __('app.other'));
    }

    public function asController(Request $request, Household $household): JsonResponse
    {
        try {
            $household_tasks = $this->handle($request->user(), $household);

            return response()->json(['household' => $household, 'tasks' => $household_tasks]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
