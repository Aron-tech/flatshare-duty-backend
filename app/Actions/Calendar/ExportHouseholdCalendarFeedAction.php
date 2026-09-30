<?php

namespace App\Actions\Calendar;

use App\Models\HouseholdUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The member's calendar of the household as an iCalendar (RFC 5545) feed, the calendar apps subscribe to it and refresh it periodically.
 * It is public, the secret token of the membership authenticates it, see GetHouseholdCalendarSubscriptionAction.
 */
class ExportHouseholdCalendarFeedAction
{
    use AsAction;

    /**
     * The feed covers the recent past and the upcoming months.
     */
    private const int PAST_DAYS = 60;

    private const int FUTURE_DAYS = 120;

    /**
     * The shortest length of an event, so a task without duration is still visible in the calendar apps.
     */
    private const int MIN_EVENT_MINUTES = 15;

    public function handle(HouseholdUser $household_user, bool $household_scope): string
    {
        $household = $household_user->household;
        $now = CarbonImmutable::now();

        $events = BuildHouseholdCalendarEventsAction::run(
            $household,
            $household_user->user,
            $household_scope,
            $now->subDays(self::PAST_DAYS)->startOfDay(),
            $now->addDays(self::FUTURE_DAYS)->startOfDay(),
        );

        $name = __($household_scope ? 'app.calendar_name_household' : 'app.calendar_name_mine', ['household' => $household->name]);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Flatshare//Calendar//'.strtoupper(app()->getLocale()),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($name),
            'X-WR-TIMEZONE:'.config('app.week_timezone'),
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
            ...$this->eventLines($events, $household_scope, $now),
            'END:VCALENDAR',
        ];

        return collect($lines)->map($this->fold(...))->implode("\r\n")."\r\n";
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @return list<string>
     */
    private function eventLines(Collection $events, bool $household_scope, CarbonImmutable $now): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'flatshare';

        return $events->flatMap(function (array $event) use ($household_scope, $now, $host): array {
            $ends_at = CarbonImmutable::parse($event['at']);
            $starts_at = $ends_at->subMinutes(max(self::MIN_EVENT_MINUTES, $event['duration_minutes']));
            $names = collect($event['assignees'])->pluck('name')->implode(', ');

            $summary = match ($event['status']) {
                BuildHouseholdCalendarEventsAction::STATUS_COMPLETED => '✓ ',
                BuildHouseholdCalendarEventsAction::STATUS_OVERDUE => '⚠ ',
                default => '',
            }.$event['name'].($household_scope && $names !== '' ? " ({$names})" : '');

            $description = array_filter([
                __('app.calendar_status_'.$event['status']),
                $event['category']['name'] ?? null,
                __('app.calendar_assignees', ['names' => $names !== '' ? $names : __('app.calendar_unassigned')]),
                $event['description'],
            ]);

            return [
                'BEGIN:VEVENT',
                "UID:{$event['id']}@{$host}",
                'DTSTAMP:'.$this->formatTime($now),
                'DTSTART:'.$this->formatTime($starts_at),
                'DTEND:'.$this->formatTime($ends_at),
                'SUMMARY:'.$this->escape($summary),
                'DESCRIPTION:'.$this->escape(implode("\n", $description)),
                'STATUS:'.($event['status'] === BuildHouseholdCalendarEventsAction::STATUS_PLANNED ? 'TENTATIVE' : 'CONFIRMED'),
                'TRANSP:TRANSPARENT',
                'END:VEVENT',
            ];
        })->all();
    }

    private function formatTime(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Ymd\THis\Z');
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /**
     * Folds the content line at 75 octets without splitting a multibyte character (RFC 5545 3.1).
     */
    private function fold(string $line): string
    {
        $folded = [];
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $folded[] = substr($line, 0, $cut);
            $line = ' '.substr($line, $cut);
        }
        $folded[] = $line;

        return implode("\r\n", $folded);
    }

    public function asController(Request $request, string $token): Response
    {
        $household_user = HouseholdUser::query()
            ->where('calendar_token', $token)
            ->whereHas('household')
            ->with(['household', 'user'])
            ->first();

        abort_unless($household_user, Response::HTTP_NOT_FOUND);

        if ($household_user->user->language) {
            app()->setLocale($household_user->user->language->value);
        }

        return response($this->handle($household_user, $request->query('scope') === 'household'), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="flatshare.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }
}
