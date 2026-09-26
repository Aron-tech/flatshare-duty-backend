<?php

namespace App\Actions\HouseholdMemberDeparture;

use App\Actions\HouseholdTask\DeleteHouseholdTaskAction;
use App\Http\Requests\ResolveHouseholdMemberDepartureRequest;
use App\Models\Household;
use App\Models\HouseholdMemberDeparture;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('manageMembers', 'household')]
class ResolveHouseholdMemberDepartureAction
{
    use AsAction;

    /**
     * Deletes the chosen tasks created by the former member (all, some or none of them) and closes the departure.
     * Only one admin can decide, the departure is locked meanwhile.
     *
     * @param  list<int>  $task_ids
     * @return int the number of deleted tasks
     *
     * @throws AuthorizationException when another admin already decided
     */
    public function handle(User $user, HouseholdMemberDeparture $departure, array $task_ids): int
    {
        return DB::transaction(function () use ($user, $departure, $task_ids): int {
            $departure = HouseholdMemberDeparture::query()->lockForUpdate()->findOrFail($departure->id);
            if ($departure->isResolved()) {
                throw new AuthorizationException(__('app.member_departure_resolved'));
            }

            $tasks = $departure->createdTasks()->whereKey($task_ids)->get();
            $tasks->each(fn (Task $task) => DeleteHouseholdTaskAction::make()->handle($task));

            $departure->update(['resolved_at' => now(), 'resolved_by' => $user->id]);

            return $tasks->count();
        });
    }

    /**
     * @return array{deleted_count: int, message: string}
     */
    public function asController(#[CurrentUser] User $user, ResolveHouseholdMemberDepartureRequest $request, Household $household, HouseholdMemberDeparture $member_departure): array
    {
        return [
            'deleted_count' => $this->handle($user, $member_departure, $request->validated('task_ids')),
            'message' => __('app.success_action'),
        ];
    }
}
