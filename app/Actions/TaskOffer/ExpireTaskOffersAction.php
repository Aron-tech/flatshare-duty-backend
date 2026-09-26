<?php

namespace App\Actions\TaskOffer;

use App\Concerns\WritesCommandOutput;
use App\Enums\TaskInstanceStatusEnum;
use App\Enums\TaskOfferStatusEnum;
use App\Models\TaskInstance;
use App\Models\TaskOffer;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;

class ExpireTaskOffersAction
{
    use AsAction;
    use WritesCommandOutput;

    public string $commandSignature = 'tasks:expire-offers {--output : Eredmény kiírása a konzolra}';

    public string $commandDescription = 'Lezárja a határidőig át nem vett vagy okafogyottá vált feladat-ajánlatokat, és visszaadja a zárolt pontot.';

    /**
     * Refunds the open offers nobody took over by the due date of their task instance,
     * and the ones whose claim or task instance is gone (e.g. the task was deleted).
     * An accepted offer whose task instance was deleted is cancelled and refunded as well;
     * the taker missing the due date is handled by ReleaseOverdueTaskClaimsAction.
     *
     * @return int the number of closed offers
     */
    public function handle(?int $household_id = null): int
    {
        $closed = 0;

        TaskOffer::query()
            ->whereIn('status', [TaskOfferStatusEnum::OPEN, TaskOfferStatusEnum::ACCEPTED])
            ->when($household_id, fn ($query) => $query->where('household_id', $household_id))
            ->with(['taskInstanceUser.taskInstance' => fn ($query) => $query->withTrashed(), 'acceptedClaim'])
            ->lazyById()
            ->each(function (TaskOffer $task_offer) use (&$closed) {
                $status = $this->closingStatus($task_offer);
                if ($status && SettleTaskOfferAction::run($task_offer, $status)) {
                    $closed++;
                }
            });

        return $closed;
    }

    private function closingStatus(TaskOffer $task_offer): ?TaskOfferStatusEnum
    {
        $task_instance = $task_offer->taskInstanceUser->taskInstance;
        $is_gone = ! $task_instance || $task_instance->trashed();

        if ($task_offer->status === TaskOfferStatusEnum::ACCEPTED) {
            return $is_gone ? TaskOfferStatusEnum::CANCELLED : null;
        }

        $offered_claim = $task_offer->taskInstanceUser;

        return $is_gone || $offered_claim->trashed() || $offered_claim->completed_at || ! $this->isOpen($task_instance)
            ? TaskOfferStatusEnum::EXPIRED
            : null;
    }

    private function isOpen(TaskInstance $task_instance): bool
    {
        return $task_instance->status === TaskInstanceStatusEnum::PENDING
            && ! $task_instance->completed_at
            && $task_instance->due_at?->isFuture();
    }

    public function asCommand(Command $command): void
    {
        $this->writeOutput($command, "Lezárt ajánlatok: {$this->handle()}");
    }
}
