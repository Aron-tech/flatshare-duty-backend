<?php

namespace App\Actions\TaskOffer;

use App\Actions\PushToken\SendPushNotificationAction;
use App\Enums\TaskOfferStatusEnum;
use App\Models\Household;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use App\Models\TaskOffer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class AcceptTaskOfferAction
{
    use AsAction;

    /**
     * The user takes over the offered claim: it is replaced by the user's claim, and the offered points are paid out
     * to the user when they complete the task, see CompleteTaskInstanceAction.
     * A taken over penalty claim keeps its weekly goal, so the task instance stays an extra, exclusive one,
     * but it covers no shortfall, the taker gets the full points, see TaskInstanceUser::isPenalty().
     * The offer can only be taken over before the due date of the task instance.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, TaskOffer $task_offer): TaskInstanceUser
    {
        $claim = DB::transaction(function () use ($user, $task_offer) {
            $task_offer = TaskOffer::query()->lockForUpdate()->findOrFail($task_offer->id);
            $offered_claim = TaskInstanceUser::query()->lockForUpdate()->find($task_offer->task_instance_user_id);
            $task_instance = $offered_claim
                ? TaskInstance::query()->with('task')->lockForUpdate()->find($offered_claim->task_instance_id)
                : null;

            $is_open = $task_instance?->isOpen() && $task_instance->due_at?->isFuture();

            if (
                ! $task_offer->isAcceptableBy($user->id)
                || ! $is_open
                || $offered_claim->completed_at
                || $task_instance->taskInstanceUsers()->where('user_id', $user->id)->exists()
            ) {
                throw new AuthorizationException(__('app.task_offer_not_acceptable'));
            }

            $offered_claim->delete();
            $claim = $task_instance->claimFor($user->id, [
                'weekly_point_goal_id' => $offered_claim->weekly_point_goal_id,
                'penalty_points' => $offered_claim->weekly_point_goal_id ? 0 : null,
                'task_offer_id' => $task_offer->id,
            ]);

            $task_offer->update([
                'status' => TaskOfferStatusEnum::ACCEPTED,
                'accepted_by' => $user->id,
                'accepted_at' => now(),
            ]);

            return $claim->setRelation('taskInstance', $task_instance)->setRelation('taskOffer', $task_offer);
        });

        $this->notify($user, $household, $claim);

        return $claim;
    }

    private function notify(User $user, Household $household, TaskInstanceUser $claim): void
    {
        $offerer = $claim->taskOffer->offeredBy;
        $locale = $offerer->language?->value;

        SendPushNotificationAction::run(
            [$offerer->id],
            __('app.task_offer_accepted_title', ['household' => $household->name], $locale),
            __('app.task_offer_accepted_body', ['name' => $user->name, 'task' => $claim->taskInstance->task->name], $locale),
            ['route' => '/', 'household_id' => $household->id],
        );
    }

    /**
     * @return array{message: string}
     */
    public function asController(#[CurrentUser] User $user, Household $household, TaskOffer $task_offer): array
    {
        $this->handle($user, $household, $task_offer);

        return ['message' => __('app.success_action')];
    }
}
