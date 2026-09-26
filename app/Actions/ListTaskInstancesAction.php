<?php

namespace App\Actions;

use App\Enums\TaskOfferStatusEnum;
use App\Models\Household;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use App\Models\TaskOffer;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class ListTaskInstancesAction
{
    use AsAction;

    /**
     * Splits the open task instances of the household into the ones the user
     * can still claim and the ones the user has already claimed.
     * Instances whose part the user has already completed are left out of both lists.
     * Penalty tasks assigned to the user are flagged with is_penalty and only earn the points above the shortfall they cover.
     * The points include the overdue bounty only when the user can get it, see CalculateTaskPointsAction::isBountyEligible().
     * The points are the user's share: split between the current claimers, the user included when claiming it.
     * An instance another member has already completed their part of cannot be joined, see TaskInstance::isJoinable().
     * The claimed ones carry the user's open offer (my_offer), the minimum points of an offer (min_offer_points, the covered shortfall of a penalty),
     * the grace day used on the claim (grace_granted_at) and the points paid out for a taken over task (offer_points).
     * The offered ones are the open offers of the others the user can take over (offer), with the user's share of the task (points).
     *
     * @return array{available: list<TaskInstance>, claimed: list<TaskInstance>, offered: list<TaskInstance>}
     */
    public function handle(User $user, Household $household): array
    {
        $member_ids = $household->householdUsers()->pluck('user_id');

        $task_instances = $household->taskInstances()
            ->open()
            ->with([
                'task.category',
                'task.userWeights' => fn ($query) => $query->whereIn('user_id', $member_ids),
                'taskInstanceUsers.taskOffer',
            ])
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $calculate_task_points = CalculateTaskPointsAction::make();
        $bountyless_ids = $calculate_task_points->missedTaskInstanceIds($user, $task_instances);

        $open_offers = TaskOffer::query()
            ->where('household_id', $household->id)
            ->where('status', TaskOfferStatusEnum::OPEN)
            ->with(['offeredBy', 'targetUser'])
            ->orderBy('id')
            ->get();

        $task_instances->each(function (TaskInstance $task_instance) use ($calculate_task_points, $user, $member_ids, $bountyless_ids, $open_offers): void {
            $claim = $task_instance->taskInstanceUsers->firstWhere('user_id', $user->id);
            $claimers = $task_instance->taskInstanceUsers->count() + ($claim ? 0 : 1);

            $task_instance->setAttribute('is_penalty', (bool) $claim?->isPenalty());
            $task_instance->setAttribute('claimers', $claimers);
            $points = $calculate_task_points->handle($task_instance, $member_ids, ! $bountyless_ids->contains($task_instance->id), $claimers, $claim?->created_at);
            $task_instance->setAttribute('points', $claim ? $claim->payablePoints($points) : $points);

            if ($claim) {
                $this->setClaimAttributes($task_instance, $claim, $points, $open_offers);
            }
        });

        [$claimed, $others] = $task_instances->partition(
            fn (TaskInstance $task_instance) => $task_instance->taskInstanceUsers->contains('user_id', $user->id)
        );

        $claimed = $claimed->reject(
            fn (TaskInstance $task_instance): bool => (bool) $task_instance->taskInstanceUsers->firstWhere('user_id', $user->id)->completed_at
        );

        $available = $others->filter(fn (TaskInstance $task_instance): bool => $task_instance->isJoinable($task_instance->taskInstanceUsers));

        return [
            'available' => $available->values()->all(),
            'claimed' => $claimed->values()->all(),
            'offered' => $this->offered($user, $others, $open_offers, $member_ids),
        ];
    }

    /**
     * @param  Collection<int, TaskOffer>  $open_offers
     */
    private function setClaimAttributes(TaskInstance $task_instance, TaskInstanceUser $claim, int $points, Collection $open_offers): void
    {
        $my_offer = $open_offers->firstWhere('task_instance_user_id', $claim->id);

        $task_instance->setAttribute('my_offer', $my_offer ? [
            'id' => $my_offer->id,
            'points' => $my_offer->points,
            'target_user_id' => $my_offer->target_user_id,
            'target_name' => $my_offer->targetUser?->name,
        ] : null);
        $task_instance->setAttribute('min_offer_points', $claim->coveredPenaltyPoints($points));
        $task_instance->setAttribute('grace_granted_at', $claim->grace_granted_at);
        $task_instance->setAttribute('offer_points', $claim->taskOffer?->points ?? 0);
    }

    /**
     * The task instances the others offered to anyone or to the user, and the user can still take over before the due date.
     * The user's share replaces the offerer's, so the number of claimers does not change. One offer is shown per task instance.
     *
     * @param  Collection<int, TaskInstance>  $task_instances  the ones the user has not claimed
     * @param  Collection<int, TaskOffer>  $open_offers
     * @param  Collection<int, int>  $member_ids
     * @return list<TaskInstance>
     */
    private function offered(User $user, Collection $task_instances, Collection $open_offers, Collection $member_ids): array
    {
        $calculate_task_points = CalculateTaskPointsAction::make();

        return $task_instances
            ->filter(fn (TaskInstance $task_instance): bool => (bool) $task_instance->due_at?->isFuture())
            ->map(function (TaskInstance $task_instance) use ($user, $open_offers, $member_ids, $calculate_task_points): ?TaskInstance {
                $offered_claim = $task_instance->taskInstanceUsers->first(fn (TaskInstanceUser $claim): bool => ! $claim->completed_at
                    && (bool) $open_offers->firstWhere('task_instance_user_id', $claim->id)?->isAcceptableBy($user->id));
                if (! $offered_claim) {
                    return null;
                }

                $task_offer = $open_offers->firstWhere('task_instance_user_id', $offered_claim->id);
                $claimers = $task_instance->taskInstanceUsers->count();

                return (clone $task_instance)
                    ->setAttribute('claimers', $claimers)
                    ->setAttribute('points', $calculate_task_points->handle($task_instance, $member_ids, false, $claimers))
                    ->setAttribute('offer', [
                        'id' => $task_offer->id,
                        'points' => $task_offer->points,
                        'offered_by' => $task_offer->offered_by,
                        'offered_by_name' => $task_offer->offeredBy->name,
                        'is_penalty' => $offered_claim->isPenalty(),
                        'is_targeted' => $task_offer->target_user_id !== null,
                    ]);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{available: list<TaskInstance>, claimed: list<TaskInstance>, offered: list<TaskInstance>}
     */
    public function asController(#[CurrentUser] User $user, Household $household): array
    {
        return $this->handle($user, $household);
    }
}
