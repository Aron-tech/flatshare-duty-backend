<?php

namespace App\Actions\Calendar;

use App\Enums\TaskAssignmentModeEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class BuildHouseholdCalendarEventsAction
{
    use AsAction;

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_OPEN = 'open';

    public const string STATUS_OVERDUE = 'overdue';

    public const string STATUS_PLANNED = 'planned';

    /**
     * The upcoming instances projected per task at most, so an hourly task cannot blow up a long range.
     */
    private const int MAX_PROJECTIONS_PER_TASK = 2000;

    /**
     * The calendar events of the household between the two moments, ordered by their moment.
     * An event is placed at its completion, or else at its due date; an open claim without a due date at the claim.
     * The existing task instances are listed, and the upcoming instances of the recurring tasks are projected (planned):
     * the next one is created when the period of the latest one elapses (or now, when it is late), is due at the end of its period,
     * and is assigned like GenerateRecurringTaskInstancesAction would: to the fixed member or to the next members of the rotation.
     * The projection assumes every instance is done in time, a late one delays the next instance, see GenerateRecurringTaskInstancesAction.
     * With $household_scope false only the viewer's events are listed: their claims and the planned instances assigned to them.
     *
     * @return Collection<int, array{
     *     id: string, task_id: int, task_instance_id: ?int, name: string, description: ?string,
     *     category: ?array{name: string, icon: ?string, color: ?string}, duration_minutes: int, is_recurring: bool,
     *     status: string, at: string, due_at: ?string, completed_at: ?string, is_mine: bool,
     *     assignees: list<array{user_id: int, name: string, completed: bool}>,
     * }>
     */
    public function handle(Household $household, User $viewer, bool $household_scope, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();
        $member_names = $household->householdUsers()->with('user')->get()->mapWithKeys(fn ($member): array => [$member->user_id => $member->user->name]);

        return $this->instanceEvents($household, $viewer, $household_scope, $from, $to)
            ->concat($this->plannedEvents($household, $viewer, $household_scope, $from, $to, $member_names))
            ->sortBy([['at', 'asc'], ['name', 'asc']])
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function instanceEvents(Household $household, User $viewer, bool $household_scope, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $household->taskInstances()
            ->with(['task.category', 'taskInstanceUsers.user'])
            ->unless($household_scope, fn ($query) => $query->whereHas('taskInstanceUsers', fn ($query) => $query->where('user_id', $viewer->id)))
            ->where(fn ($query) => $query
                ->whereBetween('completed_at', [$from, $to])
                ->orWhereHas('taskInstanceUsers', fn ($query) => $query->whereBetween('completed_at', [$from, $to]))
                ->orWhere(fn ($query) => $query->open()->where(fn ($query) => $query->whereBetween('due_at', [$from, $to])->orWhereNull('due_at'))))
            ->get()
            ->map(fn (TaskInstance $task_instance): ?array => $this->instanceEvent($task_instance, $viewer, $household_scope))
            ->filter(fn (?array $event): bool => $event !== null && $event['at'] >= $from->toIso8601String() && $event['at'] < $to->toIso8601String())
            ->values();
    }

    /**
     * @return ?array<string, mixed> null when the instance is not on the calendar: closed without completion, or open, unclaimed and without due date
     */
    private function instanceEvent(TaskInstance $task_instance, User $viewer, bool $household_scope): ?array
    {
        $claims = $task_instance->taskInstanceUsers;
        $my_claim = $claims->firstWhere('user_id', $viewer->id);

        if (! $task_instance->completed_at && ! $task_instance->isOpen()) {
            return null;
        }

        $completed_at = $household_scope ? $task_instance->completed_at : ($my_claim?->completed_at ?? $task_instance->completed_at);
        $claimed_at = $household_scope ? $claims->sortBy('created_at')->first()?->created_at : $my_claim?->created_at;
        $at = $completed_at ?? $task_instance->due_at ?? $claimed_at;

        if (! $at) {
            return null;
        }

        return $this->event(
            id: "instance-{$task_instance->id}",
            task: $task_instance->task,
            task_instance_id: $task_instance->id,
            status: match (true) {
                (bool) $completed_at => self::STATUS_COMPLETED,
                $task_instance->isOverdue() => self::STATUS_OVERDUE,
                default => self::STATUS_OPEN,
            },
            at: $at,
            due_at: $task_instance->due_at,
            completed_at: $completed_at,
            assignees: $claims
                ->sortBy('id')
                ->map(fn (TaskInstanceUser $claim): array => ['user_id' => $claim->user_id, 'name' => $claim->user->name, 'completed' => (bool) $claim->completed_at])
                ->values()
                ->all(),
            is_mine: (bool) $my_claim,
        );
    }

    /**
     * @param  Collection<int, string>  $member_names  keyed by the user id
     * @return Collection<int, array<string, mixed>>
     */
    private function plannedEvents(Household $household, User $viewer, bool $household_scope, CarbonImmutable $from, CarbonImmutable $to, Collection $member_names): Collection
    {
        return $household->tasks()
            ->recurring()
            ->whereNotNull('recurrence_interval')
            ->whereNotNull('recurrence_unit')
            ->with(['category', 'rotations'])
            ->get()
            ->flatMap(fn (Task $task): array => $this->plannedTaskEvents($task, $viewer, $household_scope, $from, $to, $member_names));
    }

    /**
     * @param  Collection<int, string>  $member_names
     * @return list<array<string, mixed>>
     */
    private function plannedTaskEvents(Task $task, User $viewer, bool $household_scope, CarbonImmutable $from, CarbonImmutable $to, Collection $member_names): array
    {
        $assignees = $this->assigneeSequence($task, $member_names);
        if (! $household_scope && ! in_array($viewer->id, $assignees, true)) {
            return [];
        }

        // The penalty instances are extra ones, the recurrence continues from the latest regular instance, see GenerateRecurringTaskInstancesAction.
        $latest = $task->taskInstances()
            ->whereDoesntHave('taskInstanceUsers', fn ($query) => $query->withTrashed()->whereNotNull('weekly_point_goal_id'))
            ->latest('id')
            ->first();

        $created_at = $latest ? $task->addRecurrencePeriod($latest->created_at) : now()->toImmutable();
        if ($created_at->isPast()) {
            $created_at = now()->toImmutable();
        }

        $events = [];
        for ($index = 0; $index < self::MAX_PROJECTIONS_PER_TASK && $created_at->lessThan($to); $index++) {
            $due_at = $task->addRecurrencePeriod($created_at);
            $user_id = $assignees === [] ? null : $assignees[$index % count($assignees)];

            if ($due_at->greaterThanOrEqualTo($from) && $due_at->lessThan($to) && ($household_scope || $user_id === $viewer->id)) {
                $events[] = $this->event(
                    id: "planned-{$task->id}-{$due_at->getTimestamp()}",
                    task: $task,
                    task_instance_id: null,
                    status: self::STATUS_PLANNED,
                    at: $due_at,
                    due_at: $due_at,
                    completed_at: null,
                    assignees: $user_id ? [['user_id' => $user_id, 'name' => $member_names[$user_id], 'completed' => false]] : [],
                    is_mine: $user_id === $viewer->id,
                );
            }

            $created_at = $due_at;
        }

        return $events;
    }

    /**
     * The members the upcoming instances are assigned to in order, repeated cyclically; empty when nobody is assigned.
     * The rotation continues after the member the latest instance was assigned to, the members who left are skipped, see AssignTaskInstanceAction.
     *
     * @param  Collection<int, string>  $member_names
     * @return list<int>
     */
    private function assigneeSequence(Task $task, Collection $member_names): array
    {
        if ($task->assignment_mode === TaskAssignmentModeEnum::FIXED) {
            return $task->fixed_user_id && $member_names->has($task->fixed_user_id) ? [$task->fixed_user_id] : [];
        }

        if ($task->assignment_mode !== TaskAssignmentModeEnum::ROTATING) {
            return [];
        }

        $rotation = $task->rotations->pluck('user_id')->filter(fn (int $user_id): bool => $member_names->has($user_id))->values();
        $last_index = $rotation->search($task->last_assigned_user_id);

        return $last_index === false ? $rotation->all() : [...$rotation->slice($last_index + 1), ...$rotation->take($last_index + 1)];
    }

    /**
     * @param  list<array{user_id: int, name: string, completed: bool}>  $assignees
     * @return array<string, mixed>
     */
    private function event(
        string $id,
        Task $task,
        ?int $task_instance_id,
        string $status,
        CarbonInterface $at,
        ?CarbonInterface $due_at,
        ?CarbonInterface $completed_at,
        array $assignees,
        bool $is_mine,
    ): array {
        return [
            'id' => $id,
            'task_id' => $task->id,
            'task_instance_id' => $task_instance_id,
            'name' => $task->name,
            'description' => $task->description,
            'category' => $task->category ? [
                'name' => $task->category->name,
                'icon' => $task->category->icon,
                'color' => $task->category->color,
            ] : null,
            'duration_minutes' => $task->duration_minutes,
            'is_recurring' => $task->is_recurring,
            'status' => $status,
            'at' => $at->toImmutable()->utc()->toIso8601String(),
            'due_at' => $due_at?->toImmutable()->utc()->toIso8601String(),
            'completed_at' => $completed_at?->toImmutable()->utc()->toIso8601String(),
            'is_mine' => $is_mine,
            'assignees' => $assignees,
        ];
    }
}
