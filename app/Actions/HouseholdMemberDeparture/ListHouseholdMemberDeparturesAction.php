<?php

namespace App\Actions\HouseholdMemberDeparture;

use App\Models\Household;
use App\Models\HouseholdMemberDeparture;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('manageMembers', 'household')]
class ListHouseholdMemberDeparturesAction
{
    use AsAction;

    /**
     * The departures the admins still have to decide about, with the tasks created by the former member.
     * A departure whose tasks were all deleted meanwhile is left out.
     *
     * @return Collection<int, array{id: int, user: array{id: int, name: string}, removed_by: ?int, created_at: mixed, tasks: Collection<int, mixed>}>
     */
    public function handle(Household $household): Collection
    {
        return $household->memberDepartures()
            ->unresolved()
            ->with('user')
            ->latest()
            ->get()
            ->map(fn (HouseholdMemberDeparture $departure): array => [
                'id' => $departure->id,
                'user' => ['id' => $departure->user->id, 'name' => $departure->user->name],
                'removed_by' => $departure->removed_by,
                'created_at' => $departure->created_at,
                'tasks' => $departure->createdTasks()
                    ->with('category')
                    ->orderBy('name')
                    ->get(['id', 'name', 'is_recurring', 'category_id'])
                    ->map(fn ($task): array => [
                        'id' => $task->id,
                        'name' => $task->name,
                        'is_recurring' => $task->is_recurring,
                        'category' => $task->category,
                    ]),
            ])
            ->filter(fn (array $departure): bool => $departure['tasks']->isNotEmpty())
            ->values();
    }

    /**
     * @return array{member_departures: Collection<int, array<string, mixed>>}
     */
    public function asController(Household $household): array
    {
        return ['member_departures' => $this->handle($household)];
    }
}
