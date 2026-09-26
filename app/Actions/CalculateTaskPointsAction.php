<?php

namespace App\Actions;

use App\Enums\PointTransactionType;
use App\Enums\TaskUserWeightEnum;
use App\Models\PointTransaction;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use App\Models\TaskUserWeight;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class CalculateTaskPointsAction
{
    use AsAction;

    private const float BOUNTY_PER_OVERDUE_DAY = 0.05;

    private const float MAX_BOUNTY_MULTIPLIER = 1.30;

    /**
     * Calculates the points of a task or a task instance, which is the same for every member:
     * the weight is the household's common price of the task, see weightMultiplier().
     * A task instance also adds the overdue bounty based on its due date, unless it is turned off
     * for a user who held the instance before its due date, see isBountyEligible().
     * The bounty is fixed when the instance is claimed, so the points shown at claiming are the ones paid,
     * and holding an overdue task does not raise its bounty.
     * The points of the task are shared evenly between its claimers, so a task is worth the same in total
     * however many members do it together.
     * Uses the task's eager loaded userWeights relation when available,
     * so listing many tasks does not run a query per task.
     *
     * @param  Collection<int, int>|null  $member_ids  the household members, queried when not given
     * @param  int  $claimers  the number of members sharing the task instance
     * @param  CarbonInterface|null  $claimed_at  when the user claimed the instance, now when not claimed yet
     */
    public function handle(Task|TaskInstance $task_or_instance, ?Collection $member_ids = null, bool $with_bounty = true, int $claimers = 1, ?CarbonInterface $claimed_at = null): int
    {
        $task = $task_or_instance instanceof TaskInstance ? $task_or_instance->task : $task_or_instance;
        $due_at = $task_or_instance instanceof TaskInstance && $with_bounty ? $task_or_instance->due_at : null;
        $member_ids ??= $task->household->householdUsers()->pluck('user_id');

        return $this->calculate($task->base_points, $this->weightMultiplier($task, $member_ids), $due_at, $claimers, $claimed_at);
    }

    public function calculate(int $base_points, float $weight_multiplier = 1.0, ?CarbonInterface $due_at = null, int $claimers = 1, ?CarbonInterface $claimed_at = null): int
    {
        return (int) round($base_points * $weight_multiplier * $this->getBountyMultiplier($due_at, $claimed_at ?? now()) / max(1, $claimers));
    }

    /**
     * The average weight multiplier of the members, a member without a weight counts as neutral.
     * Everyone gets the same points for the task, and the tasks the household dislikes are worth more.
     *
     * @param  Collection<int, int>  $member_ids
     */
    public function weightMultiplier(Task $task, Collection $member_ids): float
    {
        if ($member_ids->isEmpty()) {
            return TaskUserWeightEnum::NEUTRAL->multiplier();
        }

        $user_weights = ($task->relationLoaded('userWeights') ? $task->userWeights : $task->userWeights()->whereIn('user_id', $member_ids)->get())
            ->whereIn('user_id', $member_ids)
            ->unique('user_id');

        $weighted_sum = $user_weights->sum(fn (TaskUserWeight $user_weight): float => $user_weight->weight->multiplier());
        $neutral_sum = ($member_ids->count() - $user_weights->count()) * TaskUserWeightEnum::NEUTRAL->multiplier();

        return ($weighted_sum + $neutral_sum) / $member_ids->count();
    }

    /**
     * The overdue bounty is for rescuing a neglected task: it is not paid to a user who claimed the instance
     * before its due date, whether the claim is still open or was released, see ReleaseOverdueTaskClaimsAction.
     */
    public function isBountyEligible(TaskInstance $task_instance, User $user): bool
    {
        return ! $this->missedTaskInstanceIds($user, collect([$task_instance]))->contains($task_instance->id);
    }

    /**
     * The ids of the given task instances whose overdue bounty the user cannot get.
     *
     * @param  Collection<int, TaskInstance>  $task_instances
     * @return Collection<int, int>
     */
    public function missedTaskInstanceIds(User $user, Collection $task_instances): Collection
    {
        $task_instance_ids = $task_instances->whereNotNull('due_at')->pluck('id');
        if ($task_instance_ids->isEmpty()) {
            return collect();
        }

        $claimed_in_time_ids = TaskInstanceUser::query()
            ->join('task_instances', 'task_instances.id', '=', 'task_instance_users.task_instance_id')
            ->where('task_instance_users.user_id', $user->id)
            ->whereIn('task_instance_users.task_instance_id', $task_instance_ids)
            ->whereColumn('task_instance_users.created_at', '<=', 'task_instances.due_at')
            ->pluck('task_instance_users.task_instance_id');

        $released_ids = PointTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', PointTransactionType::MISSED_TASK_PENALTY)
            ->whereIn('task_instance_id', $task_instance_ids)
            ->pluck('task_instance_id');

        return $claimed_in_time_ids->merge($released_ids)->unique()->values();
    }

    /**
     * +5% for every full day the instance was overdue when it was claimed, at most +30%.
     */
    private function getBountyMultiplier(?CarbonInterface $due_at, CarbonInterface $claimed_at): float
    {
        if (! $due_at || $claimed_at->lessThanOrEqualTo($due_at)) {
            return 1.0;
        }

        $days_overdue = (int) $due_at->diffInDays($claimed_at);

        return min(1.0 + $days_overdue * self::BOUNTY_PER_OVERDUE_DAY, self::MAX_BOUNTY_MULTIPLIER);
    }
}
