<?php

namespace App\Actions\Calendar;

use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class ListHouseholdCalendarEventsAction
{
    use AsAction;

    /**
     * The longest range one request can ask for, a month view with its leading and trailing days fits in it.
     */
    public const int MAX_RANGE_DAYS = 62;

    /**
     * The viewer's calendar events (scope=mine, default) or the whole household's (scope=household) in [from, to),
     * see BuildHouseholdCalendarEventsAction.
     *
     * @return array{events: list<array<string, mixed>>}
     */
    public function asController(Request $request, #[CurrentUser] User $user, Household $household): array
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'scope' => ['nullable', 'in:mine,household'],
        ]);

        $from = CarbonImmutable::parse($request->string('from')->toString());
        $to = CarbonImmutable::parse($request->string('to')->toString());
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $to = $from->addDays(self::MAX_RANGE_DAYS);
        }

        return [
            'events' => BuildHouseholdCalendarEventsAction::run($household, $user, $request->input('scope') === 'household', $from, $to)->all(),
        ];
    }
}
