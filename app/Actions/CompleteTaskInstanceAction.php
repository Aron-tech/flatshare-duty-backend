<?php

namespace App\Actions;

use App\Actions\TaskOffer\SettleTaskOfferAction;
use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Enums\PointTransactionType;
use App\Enums\TaskInstanceStatusEnum;
use App\Enums\TaskOfferStatusEnum;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class CompleteTaskInstanceAction
{
    use AsAction;

    /**
     * Completes the user's claim on the task instance and credits the earned points, the task's points shared between its claimers.
     * A penalty task assigned for missing the weekly minimum points only earns the points above the shortfall it covers.
     * A task taken over through an offer also pays out the offered points (DELEGATION_PAYOUT), and the user's own open offer
     * of the task is withdrawn with a refund, see SettleTaskOfferAction.
     * The task instance itself is closed once every claimer has completed their part.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, TaskInstance $task_instance): PointTransaction
    {
        $household_user = GetHouseholdUserAction::run($user, $household);

        return DB::transaction(function () use ($user, $household_user, $task_instance): PointTransaction {
            $task_instance = TaskInstance::query()->with('task')->lockForUpdate()->findOrFail($task_instance->id);
            $claims = $task_instance->taskInstanceUsers()->lockForUpdate()->get();
            $claim = $claims->firstWhere('user_id', $user->id);

            if (! $task_instance->isOpen() || ! $claim || $claim->completed_at) {
                throw new AuthorizationException(__('app.task_instance_not_completable'));
            }

            $claim->update(['completed_at' => now()]);

            if ($claims->every(fn (TaskInstanceUser $task_instance_user): bool => (bool) $task_instance_user->completed_at)) {
                $task_instance->update(['status' => TaskInstanceStatusEnum::ACCEPTED, 'completed_at' => now()]);
            }

            $calculate_task_points = CalculateTaskPointsAction::make();
            $points = $calculate_task_points->handle(
                $task_instance,
                with_bounty: $calculate_task_points->isBountyEligible($task_instance, $user),
                claimers: $claims->count(),
                claimed_at: $claim->created_at,
            );

            $point_transaction = $this->creditPoints(
                $household_user,
                $task_instance,
                $claim->payablePoints($points),
                $claim->isPenalty() ? PointTransactionType::PENALTY_TASK_COMPLETION : PointTransactionType::TASK_COMPLETION,
            );

            if ($claim->taskOffer) {
                SettleTaskOfferAction::run($claim->taskOffer, TaskOfferStatusEnum::COMPLETED);
            }
            $claim->offers()->where('status', TaskOfferStatusEnum::OPEN)->each(
                fn ($task_offer) => SettleTaskOfferAction::run($task_offer, TaskOfferStatusEnum::CANCELLED, from: [TaskOfferStatusEnum::OPEN]),
            );

            return $point_transaction;
        });
    }

    private function creditPoints(HouseholdUser $household_user, TaskInstance $task_instance, int $points, PointTransactionType $type): PointTransaction
    {
        $household_user = HouseholdUser::query()->lockForUpdate()->findOrFail($household_user->id);
        $household_user->increment('points_balance', $points);

        return PointTransaction::create([
            'household_id' => $household_user->household_id,
            'user_id' => $household_user->user_id,
            'amount' => $points,
            'balance_after' => $household_user->points_balance,
            'type' => $type,
            'task_instance_id' => $task_instance->id,
        ]);
    }

    /**
     * offer_points: the points paid out for a task taken over through an offer.
     *
     * @return array{points: int, offer_points: int, points_balance: int, weekly_points: int, spendable_points: int, message: string}
     */
    public function asController(#[CurrentUser] User $user, Household $household, TaskInstance $task_instance): array
    {
        $point_transaction = $this->handle($user, $household, $task_instance);
        $household_user = GetHouseholdUserAction::run($user, $household);
        RecalculateWeeklyPointGoalsAction::make()->currentGoals($household);

        return [
            'points' => $point_transaction->amount,
            'offer_points' => (int) PointTransaction::query()
                ->where('user_id', $household_user->user_id)
                ->where('task_instance_id', $task_instance->id)
                ->where('type', PointTransactionType::DELEGATION_PAYOUT)
                ->sum('amount'),
            'points_balance' => $household_user->points_balance,
            'weekly_points' => $household_user->weeklyPoints(),
            'spendable_points' => $household_user->spendablePoints(),
            'message' => __('app.success_action'),
        ];
    }
}
