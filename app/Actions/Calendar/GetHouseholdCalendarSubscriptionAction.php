<?php

namespace App\Actions\Calendar;

use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class GetHouseholdCalendarSubscriptionAction
{
    use AsAction;

    /**
     * The addresses of the member's calendar feeds, the secret is created on the first request.
     * The webcal addresses open the subscription in the calendar apps, the https ones can be added by URL (Google Calendar).
     *
     * @return array{mine: array{url: string, webcal_url: string}, household: array{url: string, webcal_url: string}}
     *
     * @throws AuthorizationException when the user is not a member of the household
     */
    public function handle(User $user, Household $household): array
    {
        $household_user = $user->membershipOf($household) ?? throw new AuthorizationException(__('app.no_permission'));

        return [
            'mine' => $this->urls($household_user, 'mine'),
            'household' => $this->urls($household_user, 'household'),
        ];
    }

    /**
     * @return array{url: string, webcal_url: string}
     */
    private function urls(HouseholdUser $household_user, string $scope): array
    {
        $url = route('calendar.feed', ['token' => $household_user->calendarToken(), 'scope' => $scope]);

        return [
            'url' => $url,
            'webcal_url' => preg_replace('#^https?://#', 'webcal://', $url),
        ];
    }

    /**
     * @return array{mine: array{url: string, webcal_url: string}, household: array{url: string, webcal_url: string}}
     */
    public function asController(#[CurrentUser] User $user, Household $household): array
    {
        return $this->handle($user, $household);
    }
}
