<?php

namespace App\Actions\RecurringTask;

use App\Actions\PushToken\SendPushNotificationAction;
use App\Actions\TaskOffer\SettleTaskOfferAction;
use App\Concerns\WritesCommandOutput;
use App\Enums\PointTransactionType;
use App\Enums\TaskOfferStatusEnum;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\TaskInstanceUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class ReleaseOverdueTaskClaimsAction
{
    use AsAction;
    use WritesCommandOutput;

    public string $commandSignature = 'tasks:release-overdue {--output : Eredmény kiírása a konzolra}';

    public string $commandDescription = 'Felszabadítja a határidőig nem teljesített vállalásokat, hogy bárki elvállalhassa a feladatot a késési bónuszért. A lejárt büntető feladat hiányát levonja.';

    /**
     * Releases the claims that were made before the due date of their open task instance but were not completed by then,
     * so any member can take over the task for the overdue bounty. The task instance stays open.
     * The released member can claim it again, but without the bounty, see CalculateTaskPointsAction::isBountyEligible().
     * Every released claim is recorded as a missed task penalty. A missed penalty task costs the shortfall it covers:
     * it is deducted from the balance (never below zero), so ignoring a penalty is not free.
     * A task taken over through an offer and missed fails the offer, see SettleTaskOfferAction.
     *
     * @return int the number of released claims
     */
    public function handle(): int
    {
        $released = 0;

        TaskInstanceUser::query()
            ->whereNull('completed_at')
            ->whereHas('taskInstance', fn ($query) => $query
                ->whereNull('deleted_at')
                ->whereHas('household')
                ->open()
                ->where('due_at', '<', now()))
            ->with(['taskInstance.task', 'taskInstance.household', 'taskOffer', 'user'])
            ->lazyById()
            ->each(function (TaskInstanceUser $claim) use (&$released) {
                if ($claim->created_at->greaterThan($claim->taskInstance->due_at)) {
                    return;
                }

                $this->release($claim);
                $released++;
            });

        return $released;
    }

    private function release(TaskInstanceUser $claim): void
    {
        $deducted_points = DB::transaction(function () use ($claim) {
            $claim->delete();

            if ($claim->taskOffer) {
                SettleTaskOfferAction::run($claim->taskOffer, TaskOfferStatusEnum::FAILED);
            }

            $household_user = HouseholdUser::query()
                ->where('household_id', $claim->taskInstance->household_id)
                ->where('user_id', $claim->user_id)
                ->lockForUpdate()
                ->first();
            if (! $household_user) {
                return 0;
            }

            $deducted_points = $claim->isPenalty() ? min($claim->penalty_points ?? 0, max(0, $household_user->points_balance)) : 0;
            if ($deducted_points > 0) {
                $household_user->decrement('points_balance', $deducted_points);
            }

            PointTransaction::create([
                'household_id' => $household_user->household_id,
                'user_id' => $household_user->user_id,
                'amount' => $deducted_points,
                'balance_after' => $household_user->points_balance,
                'type' => PointTransactionType::MISSED_TASK_PENALTY,
                'task_instance_id' => $claim->task_instance_id,
            ]);

            return $deducted_points;
        });

        if ($claim->isPenalty()) {
            $this->notifyMissedPenalty($claim, $deducted_points);
        }
    }

    private function notifyMissedPenalty(TaskInstanceUser $claim, int $deducted_points): void
    {
        $locale = $claim->user->language?->value;

        SendPushNotificationAction::run(
            [$claim->user_id],
            __('app.penalty_missed_title', ['household' => $claim->taskInstance->household->name], $locale),
            __('app.penalty_missed_body', ['task' => $claim->taskInstance->task->name, 'points' => $deducted_points], $locale),
            ['route' => '/', 'household_id' => $claim->taskInstance->household_id],
        );
    }

    public function asCommand(Command $command): void
    {
        $this->writeOutput($command, "Felszabadított vállalások: {$this->handle()}");
    }
}
