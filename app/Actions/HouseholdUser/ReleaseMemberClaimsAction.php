<?php

namespace App\Actions\HouseholdUser;

use App\Actions\TaskOffer\SettleTaskOfferAction;
use App\Enums\TaskOfferStatusEnum;
use App\Models\TaskInstanceUser;
use App\Models\TaskOffer;
use Lorisleiva\Actions\Concerns\AsAction;

class ReleaseMemberClaimsAction
{
    use AsAction;

    /**
     * Releases the open claims of a member leaving the household, so their tasks (penalty tasks included) go back to the pool
     * instead of being blocked forever. The member's open offers are cancelled (the refund goes to the balance removed with the membership);
     * a task they took over through an offer counts as missed, so the offerer's penalty part of the escrow is burned.
     */
    public function handle(int $household_id, int $user_id): void
    {
        TaskOffer::query()
            ->where('household_id', $household_id)
            ->where('offered_by', $user_id)
            ->where('status', TaskOfferStatusEnum::OPEN)
            ->each(fn (TaskOffer $task_offer) => SettleTaskOfferAction::run($task_offer, TaskOfferStatusEnum::CANCELLED, false));

        TaskInstanceUser::query()
            ->where('user_id', $user_id)
            ->whereNull('completed_at')
            ->whereHas('taskInstance', fn ($query) => $query
                ->where('household_id', $household_id)
                ->open())
            ->with('taskOffer')
            ->each(function (TaskInstanceUser $claim) {
                $claim->delete();

                if ($claim->taskOffer) {
                    SettleTaskOfferAction::run($claim->taskOffer, TaskOfferStatusEnum::FAILED);
                }
            });
    }
}
