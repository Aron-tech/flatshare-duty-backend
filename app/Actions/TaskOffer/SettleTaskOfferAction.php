<?php

namespace App\Actions\TaskOffer;

use App\Actions\PushToken\SendPushNotificationAction;
use App\Enums\PointTransactionType;
use App\Enums\TaskOfferStatusEnum;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\TaskOffer;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class SettleTaskOfferAction
{
    use AsAction;

    /**
     * Closes an open or accepted offer and moves its escrowed points:
     * completed → paid out to the taker; cancelled or expired → refunded to the offerer;
     * failed (the taker missed the task) → the part covering the offerer's penalty is burned, the rest is refunded,
     * so a member cannot escape a penalty by handing it to someone who does not do it.
     * Nothing is credited to a member who has left the household.
     *
     * @param  list<TaskOfferStatusEnum>|null  $from  the statuses the offer can be settled from, by default the ones leading to the given status
     * @return bool false when the offer is not in one of those statuses, e.g. it was settled or taken over meanwhile
     */
    public function handle(TaskOffer $task_offer, TaskOfferStatusEnum $status, bool $notify = true, ?array $from = null): bool
    {
        $from ??= match ($status) {
            TaskOfferStatusEnum::COMPLETED, TaskOfferStatusEnum::FAILED => [TaskOfferStatusEnum::ACCEPTED],
            TaskOfferStatusEnum::EXPIRED => [TaskOfferStatusEnum::OPEN],
            default => [TaskOfferStatusEnum::OPEN, TaskOfferStatusEnum::ACCEPTED],
        };

        $settled = DB::transaction(function () use ($task_offer, $status, $from) {
            $task_offer = TaskOffer::query()->lockForUpdate()->findOrFail($task_offer->id);
            if (! in_array($task_offer->status, $from, true)) {
                return null;
            }

            $burned = $status === TaskOfferStatusEnum::FAILED ? min($task_offer->points, $task_offer->penalty_points) : 0;

            if ($status === TaskOfferStatusEnum::COMPLETED) {
                $this->credit($task_offer, $task_offer->accepted_by, $task_offer->points, PointTransactionType::DELEGATION_PAYOUT);
            } else {
                $this->credit($task_offer, $task_offer->offered_by, $task_offer->points - $burned, PointTransactionType::DELEGATION_ESCROW_REFUND);
                $this->burn($task_offer, $burned);
            }

            $task_offer->update(['status' => $status, 'resolved_at' => now()]);

            return $task_offer;
        });

        if ($settled && $notify) {
            DB::afterCommit(fn () => $this->notify($settled, $status));
        }

        return (bool) $settled;
    }

    private function credit(TaskOffer $task_offer, ?int $user_id, int $points, PointTransactionType $type): void
    {
        $household_user = HouseholdUser::query()
            ->where('household_id', $task_offer->household_id)
            ->where('user_id', $user_id)
            ->lockForUpdate()
            ->first();
        if (! $household_user || $points <= 0) {
            return;
        }

        $household_user->increment('points_balance', $points);

        $this->record($task_offer, $household_user, $points, $type);
    }

    /**
     * The burned points were deducted when they were escrowed, so the balance does not change.
     */
    private function burn(TaskOffer $task_offer, int $points): void
    {
        $household_user = HouseholdUser::query()
            ->where('household_id', $task_offer->household_id)
            ->where('user_id', $task_offer->offered_by)
            ->first();
        if (! $household_user || $points <= 0) {
            return;
        }

        $this->record($task_offer, $household_user, $points, PointTransactionType::DELEGATION_PENALTY_BURN);
    }

    private function record(TaskOffer $task_offer, HouseholdUser $household_user, int $points, PointTransactionType $type): void
    {
        PointTransaction::create([
            'household_id' => $household_user->household_id,
            'user_id' => $household_user->user_id,
            'amount' => $points,
            'balance_after' => $household_user->points_balance,
            'type' => $type,
            'task_instance_id' => $task_offer->taskInstanceUser->task_instance_id,
            'task_offer_id' => $task_offer->id,
        ]);
    }

    /**
     * The offerer is told when their offer ran out or the taker missed the task, they withdrew or completed it themselves otherwise.
     */
    private function notify(TaskOffer $task_offer, TaskOfferStatusEnum $status): void
    {
        if (! in_array($status, [TaskOfferStatusEnum::EXPIRED, TaskOfferStatusEnum::FAILED], true)) {
            return;
        }

        $task_offer->loadMissing(['household', 'offeredBy', 'acceptedBy', 'taskInstanceUser.taskInstance.task']);
        $locale = $task_offer->offeredBy->language?->value;
        $refunded = $task_offer->points - ($status === TaskOfferStatusEnum::FAILED ? min($task_offer->points, $task_offer->penalty_points) : 0);

        SendPushNotificationAction::run(
            [$task_offer->offered_by],
            __('app.task_offer_'.$status->value.'_title', ['household' => $task_offer->household->name], $locale),
            __('app.task_offer_'.$status->value.'_body', [
                'task' => $task_offer->taskInstanceUser->taskInstance->task->name,
                'name' => $task_offer->acceptedBy?->name ?? '',
                'points' => $refunded,
            ], $locale),
            ['route' => '/', 'household_id' => $task_offer->household_id],
        );
    }
}
