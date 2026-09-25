<?php

namespace App\Actions\HouseholdStats;

use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Enums\PointTransactionType;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\TaskInstanceUser;
use App\Models\User;
use App\Models\WeeklyPointGoal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class GetHouseholdStatsAction
{
    use AsAction;

    private const int ACTIVITY_LIMIT = 5;

    /**
     * Builds the household statistics for the current weekly cycle (Monday 00:00 to next Monday).
     *
     * @return array{
     *     cycle: array{number: int, starts_at: string, ends_at: string, total_points: int, target_points: int},
     *     balance_percent: int,
     *     members: list<array{user_id: int, name: string, role: string, points: int, target: int, is_me: bool}>,
     *     penalties: list<array{id: string, user_id: int, user_name: string, task_name: string, status: string, due_at: ?string}>,
     *     activity: list<array{id: int, user_id: int, user_name: string, is_me: bool, task_name: string, category_icon: ?string, points: int, completed_at: string}>,
     * }
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household): array
    {
        if (! $user->households()->whereKey($household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        $starts_at = CarbonImmutable::now()->startOfWeek();
        $ends_at = $starts_at->addWeek();
        $goals = RecalculateWeeklyPointGoalsAction::make()->currentGoals($household);

        $members = $this->members($user, $household, $starts_at, $ends_at, $goals);
        $total_points = (int) $members->sum('points');

        return [
            'cycle' => [
                'number' => $starts_at->isoWeek(),
                'starts_at' => $starts_at->toIso8601String(),
                'ends_at' => $ends_at->toIso8601String(),
                'total_points' => $total_points,
                'target_points' => (int) $members->sum('target'),
            ],
            'balance_percent' => $this->balancePercent($members->pluck('points')),
            'members' => $members->all(),
            'penalties' => [
                ...$this->weeklyGoalPenalties($household, $starts_at, $ends_at),
                ...$this->pendingPenalties($household),
                ...$this->resolvedPenalties($household, $starts_at, $ends_at),
            ],
            'activity' => $this->activity($user, $household),
        ];
    }

    /**
     * @param  Collection<int, WeeklyPointGoal>  $goals  keyed by user id
     * @return Collection<int, array{user_id: int, name: string, role: string, points: int, target: int, is_me: bool}>
     */
    private function members(User $user, Household $household, CarbonImmutable $starts_at, CarbonImmutable $ends_at, Collection $goals): Collection
    {
        $points_by_user = $this->completionTransactions($household)
            ->whereBetween('created_at', [$starts_at, $ends_at])
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(amount) as points')
            ->pluck('points', 'user_id');

        return $household->householdUsers()
            ->with('user')
            ->get()
            ->map(fn (HouseholdUser $household_user) => [
                'user_id' => $household_user->user_id,
                'name' => $household_user->user->name,
                'role' => $household_user->role->value,
                'points' => (int) ($points_by_user[$household_user->user_id] ?? 0),
                'target' => $goals->get($household_user->user_id)?->target_points ?? 0,
                'is_me' => $household_user->user_id === $user->id,
            ])
            ->sortByDesc('points')
            ->values();
    }

    /**
     * 100 means every member earned the same amount of points in the cycle,
     * it decreases with the coefficient of variation of the members' points.
     *
     * @param  Collection<int, int>  $points
     */
    private function balancePercent(Collection $points): int
    {
        $average = $points->avg();
        if (! $average) {
            return 100;
        }

        $variance = $points->avg(fn (int $member_points) => ($member_points - $average) ** 2);
        $coefficient_of_variation = sqrt($variance) / $average;

        return (int) round(max(0, 1 - $coefficient_of_variation) * 100);
    }

    /**
     * Tasks assigned for missing the weekly minimum points: the open ones and the ones completed in the cycle.
     *
     * @return list<array{id: string, user_id: int, user_name: string, task_name: string, status: string, due_at: ?string}>
     */
    private function weeklyGoalPenalties(Household $household, CarbonImmutable $starts_at, CarbonImmutable $ends_at): array
    {
        return TaskInstanceUser::query()
            ->whereNotNull('weekly_point_goal_id')
            ->whereHas('taskInstance', fn ($query) => $query->where('household_id', $household->id))
            ->where(fn ($query) => $query
                ->whereNull('completed_at')
                ->orWhereBetween('completed_at', [$starts_at, $ends_at]))
            ->with(['user', 'taskInstance.task'])
            ->orderBy('id')
            ->get()
            ->map(fn (TaskInstanceUser $task_instance_user) => [
                'id' => "weekly-goal-{$task_instance_user->id}",
                'user_id' => $task_instance_user->user_id,
                'user_name' => $task_instance_user->user->name,
                'task_name' => $task_instance_user->taskInstance->task->name,
                'status' => $task_instance_user->completed_at ? 'resolved' : 'pending',
                'due_at' => $task_instance_user->taskInstance->due_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Overdue claims that have not been completed yet, except the weekly goal penalties.
     *
     * @return list<array{id: string, user_id: int, user_name: string, task_name: string, status: string, due_at: ?string}>
     */
    private function pendingPenalties(Household $household): array
    {
        return TaskInstanceUser::query()
            ->whereNull('completed_at')
            ->whereNull('weekly_point_goal_id')
            ->whereHas('taskInstance', fn ($query) => $query
                ->where('household_id', $household->id)
                ->where('status', TaskInstanceStatusEnum::PENDING)
                ->whereNull('completed_at')
                ->where('due_at', '<', now()))
            ->with(['user', 'taskInstance.task'])
            ->get()
            ->map(fn (TaskInstanceUser $task_instance_user) => [
                'id' => "pending-{$task_instance_user->id}",
                'user_id' => $task_instance_user->user_id,
                'user_name' => $task_instance_user->user->name,
                'task_name' => $task_instance_user->taskInstance->task->name,
                'status' => 'pending',
                'due_at' => $task_instance_user->taskInstance->due_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Missed task penalties charged in the cycle.
     *
     * @return list<array{id: string, user_id: int, user_name: string, task_name: string, status: string, due_at: ?string}>
     */
    private function resolvedPenalties(Household $household, CarbonImmutable $starts_at, CarbonImmutable $ends_at): array
    {
        return PointTransaction::query()
            ->where('household_id', $household->id)
            ->where('type', PointTransactionType::MISSED_TASK_PENALTY)
            ->whereBetween('created_at', [$starts_at, $ends_at])
            ->with(['user', 'taskInstance.task'])
            ->latest()
            ->get()
            ->map(fn (PointTransaction $point_transaction) => [
                'id' => "resolved-{$point_transaction->id}",
                'user_id' => $point_transaction->user_id,
                'user_name' => $point_transaction->user->name,
                'task_name' => $point_transaction->taskInstance?->task->name ?? '',
                'status' => 'resolved',
                'due_at' => null,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, user_id: int, user_name: string, is_me: bool, task_name: string, category_icon: ?string, points: int, completed_at: string}>
     */
    private function activity(User $user, Household $household): array
    {
        return ListHouseholdActivityAction::make()->handle($user, $household, self::ACTIVITY_LIMIT)['data'];
    }

    private function completionTransactions(Household $household): Builder
    {
        return PointTransaction::query()
            ->where('household_id', $household->id)
            ->where('type', PointTransactionType::TASK_COMPLETION);
    }

    public function asController(Request $request, Household $household): JsonResponse
    {
        try {
            return response()->json($this->handle($request->user(), $household));
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
