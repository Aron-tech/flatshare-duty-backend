<?php

namespace App\Actions\Calendar;

use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class ResetHouseholdCalendarSubscriptionAction
{
    use AsAction;

    /**
     * Revokes the member's calendar feeds: the subscribed calendars stop updating, the next request creates new addresses.
     *
     * @throws AuthorizationException when the user is not a member of the household
     */
    public function handle(User $user, Household $household): void
    {
        $household_user = $user->membershipOf($household) ?? throw new AuthorizationException(__('app.no_permission'));

        $household_user->forceFill(['calendar_token' => null])->saveQuietly();
    }

    /**
     * @return array{message: string}
     */
    public function asController(#[CurrentUser] User $user, Household $household): array
    {
        $this->handle($user, $household);

        return ['message' => __('app.calendar_subscription_reset')];
    }
}
