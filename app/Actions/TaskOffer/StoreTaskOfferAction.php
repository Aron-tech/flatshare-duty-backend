<?php

namespace App\Actions\TaskOffer;

use App\Actions\CalculateTaskPointsAction;
use App\Actions\GetHouseholdUserAction;
use App\Actions\PushToken\SendPushNotificationAction;
use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Enums\PointTransactionType;
use App\Enums\TaskOfferStatusEnum;
use App\Http\Requests\StoreTaskOfferRequest;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\TaskInstance;
use App\Models\TaskOffer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class StoreTaskOfferAction
{
    use AsAction;

    /**
     * Offers the user's open claim to the other members (or to one of them) for points taken from the user's spendable points
     * and held in escrow until the offer is settled, see SettleTaskOfferAction.
     * Only a claim on a task instance with a due date that has not passed can be offered, and only once at a time.
     * A penalty claim has to offer at least the shortfall it covers: the taker is paid in full, so the penalty becomes a point deduction.
     *
     * @param  array{points: int, target_user_id?: ?int}  $data
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, TaskInstance $task_instance, array $data): TaskOffer
    {
        $household_user = GetHouseholdUserAction::run($user, $household);

        $target_user_id = $data['target_user_id'] ?? null;
        if ($target_user_id && ($target_user_id === $user->id || ! $household->householdUsers()->where('user_id', $target_user_id)->exists())) {
            throw new AuthorizationException(__('app.task_offer_target_not_member'));
        }

        RecalculateWeeklyPointGoalsAction::make()->currentGoals($household);

        $task_offer = DB::transaction(function () use ($household_user, $task_instance, $data, $target_user_id) {
            $task_instance = TaskInstance::query()->with('task')->lockForUpdate()->findOrFail($task_instance->id);
            $claims = $task_instance->taskInstanceUsers()->lockForUpdate()->get();
            $claim = $claims->firstWhere('user_id', $household_user->user_id);

            if (! $task_instance->isOpen() || ! $claim || $claim->completed_at || ! $task_instance->due_at || $task_instance->due_at->isPast()) {
                throw new AuthorizationException(__('app.task_offer_not_allowed'));
            }

            if ($claim->offers()->where('status', TaskOfferStatusEnum::OPEN)->exists()) {
                throw new AuthorizationException(__('app.task_offer_already_open'));
            }

            $points = CalculateTaskPointsAction::make()->handle($task_instance, claimers: $claims->count(), claimed_at: $claim->created_at);
            $penalty_points = $claim->coveredPenaltyPoints($points);
            if ($data['points'] < $penalty_points) {
                throw new AuthorizationException(__('app.task_offer_below_penalty', ['points' => $penalty_points]));
            }

            $household_user = HouseholdUser::query()->lockForUpdate()->findOrFail($household_user->id);
            if ($household_user->spendablePoints() < $data['points']) {
                throw new AuthorizationException(__('app.task_offer_not_enough_points'));
            }

            $task_offer = TaskOffer::create([
                'household_id' => $household_user->household_id,
                'task_instance_user_id' => $claim->id,
                'offered_by' => $household_user->user_id,
                'target_user_id' => $target_user_id,
                'points' => $data['points'],
                'penalty_points' => $penalty_points,
                'status' => TaskOfferStatusEnum::OPEN,
            ]);

            if ($data['points'] > 0) {
                $household_user->decrement('points_balance', $data['points']);

                PointTransaction::create([
                    'household_id' => $household_user->household_id,
                    'user_id' => $household_user->user_id,
                    'amount' => $data['points'],
                    'balance_after' => $household_user->points_balance,
                    'type' => PointTransactionType::DELEGATION_ESCROW_LOCK,
                    'task_instance_id' => $task_instance->id,
                    'task_offer_id' => $task_offer->id,
                ]);
            }

            return $task_offer->setRelation('taskInstanceUser', $claim->setRelation('taskInstance', $task_instance));
        });

        $this->notify($user, $household, $task_offer);

        return $task_offer;
    }

    /**
     * The addressed member, or every other member of an offer to anyone.
     */
    private function notify(User $user, Household $household, TaskOffer $task_offer): void
    {
        $recipients = $task_offer->target_user_id
            ? User::query()->whereKey($task_offer->target_user_id)->get()
            : User::query()->whereIn('id', $household->householdUsers()->where('user_id', '!=', $user->id)->select('user_id'))->get();

        $recipients->groupBy(fn (User $recipient) => $recipient->language?->value)->each(function ($users, $locale) use ($user, $household, $task_offer) {
            $locale = $locale ?: null;
            SendPushNotificationAction::run(
                $users->pluck('id'),
                __('app.task_offer_created_title', ['household' => $household->name], $locale),
                __('app.task_offer_created_body', [
                    'name' => $user->name,
                    'task' => $task_offer->taskInstanceUser->taskInstance->task->name,
                    'points' => $task_offer->points,
                ], $locale),
                ['route' => '/', 'household_id' => $household->id],
            );
        });
    }

    /**
     * @return array{task_offer: TaskOffer, spendable_points: int, message: string}
     */
    public function asController(#[CurrentUser] User $user, StoreTaskOfferRequest $request, Household $household, TaskInstance $task_instance): array
    {
        $task_offer = $this->handle($user, $household, $task_instance, $request->validated());

        return [
            'task_offer' => $task_offer->withoutRelations(),
            'spendable_points' => GetHouseholdUserAction::run($user, $household)->spendablePoints(),
            'message' => __('app.success_action'),
        ];
    }
}
