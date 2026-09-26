<?php

namespace App\Actions\TaskOffer;

use App\Actions\GetHouseholdUserAction;
use App\Enums\TaskOfferStatusEnum;
use App\Models\Household;
use App\Models\TaskOffer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class CancelTaskOfferAction
{
    use AsAction;

    /**
     * The offerer withdraws their offer while nobody has taken it over, the escrowed points are refunded.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, TaskOffer $task_offer): void
    {
        if ($task_offer->offered_by !== $user->id) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        if (! SettleTaskOfferAction::run($task_offer, TaskOfferStatusEnum::CANCELLED, from: [TaskOfferStatusEnum::OPEN])) {
            throw new AuthorizationException(__('app.task_offer_not_cancellable'));
        }
    }

    /**
     * @return array{spendable_points: int, message: string}
     */
    public function asController(#[CurrentUser] User $user, Household $household, TaskOffer $task_offer): array
    {
        $this->handle($user, $task_offer);

        return [
            'spendable_points' => GetHouseholdUserAction::run($user, $household)->spendablePoints(),
            'message' => __('app.success_action'),
        ];
    }
}
