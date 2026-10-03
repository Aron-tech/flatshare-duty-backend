<?php

namespace App\Actions\House;

use App\Actions\HouseholdStats\GetHouseholdStatsAction;
use App\Actions\HouseholdStats\ListHouseholdActivityAction;
use App\Enums\HouseMoodEnum;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\TaskInstance;
use App\Models\User;
use App\Models\WeeklyPointGoal;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class GetHouseStateAction
{
    use AsAction;

    public const int MAX_MESS_LEVEL = 3;

    /** An overdue task older than this makes its zone a total mess. */
    public const int STALE_OVERDUE_HOURS = 48;

    /** Each mess level costs this much mood, at most MAX_MESS_PENALTY in total. */
    public const int MESS_LEVEL_PENALTY = 8;

    public const int MAX_MESS_PENALTY = 60;

    public const int PENDING_PENALTY_COST = 5;

    /** When the household earned less points than the cycle's elapsed share of the target (with WeeklyPointGoal::TOLERANCE). */
    public const int BEHIND_PACE_COST = 15;

    private const int RECENT_COMPLETIONS_LIMIT = 10;

    /**
     * The state of the household's house view: a mess level for each task category with open task instances,
     * the shared mood of the household and the members' characters.
     *
     * @return array{
     *     mood: array{score: int, band: string, behind_pace: bool, pending_penalties: int},
     *     zones: list<array{category_id: ?int, category_icon: ?string, category_name: ?string, open: int, due_today: int, overdue: int, mess_level: int}>,
     *     members: list<array{user_id: int, name: string, role: string, character: string, is_me: bool}>,
     *     recent_completions: list<array{id: int, user_id: int, category_icon: ?string, completed_at: string}>,
     * }
     */
    public function handle(User $user, Household $household): array
    {
        $stats = GetHouseholdStatsAction::make()->handle($user, $household);
        $zones = $this->zones($household);
        $behind_pace = $this->isBehindPace($stats['cycle']);
        $pending_penalties = collect($stats['penalties'])->where('status', 'pending')->count();

        $score = 100
            - min(self::MAX_MESS_PENALTY, self::MESS_LEVEL_PENALTY * $zones->sum('mess_level'))
            - self::PENDING_PENALTY_COST * $pending_penalties
            - ($behind_pace ? self::BEHIND_PACE_COST : 0);
        $score = max(0, min(100, $score));

        return [
            'mood' => [
                'score' => $score,
                'band' => HouseMoodEnum::fromScore($score)->value,
                'behind_pace' => $behind_pace,
                'pending_penalties' => $pending_penalties,
            ],
            'zones' => $zones->all(),
            'members' => $this->members($user, $household),
            'recent_completions' => collect(ListHouseholdActivityAction::make()->handle($user, $household, self::RECENT_COMPLETIONS_LIMIT)['data'])
                ->map(fn (array $activity): array => [
                    'id' => $activity['id'],
                    'user_id' => $activity['user_id'],
                    'category_icon' => $activity['category_icon'],
                    'completed_at' => $activity['completed_at'],
                ])
                ->all(),
        ];
    }

    /**
     * The open task instances grouped by the task's category (null: tasks without a category).
     * Mess level: 3 = two or more overdue, or one overdue for more than STALE_OVERDUE_HOURS; 2 = one overdue;
     * 1 = due today (in app.week_timezone); 0 = nothing urgent.
     *
     * @return Collection<int, array{category_id: ?int, category_icon: ?string, category_name: ?string, open: int, due_today: int, overdue: int, mess_level: int}>
     */
    private function zones(Household $household): Collection
    {
        $now = CarbonImmutable::now();
        $day_ends_at = $now->setTimezone(config('app.week_timezone'))->endOfDay();
        $stale_before = $now->subHours(self::STALE_OVERDUE_HOURS);

        return TaskInstance::query()
            ->where('household_id', $household->id)
            ->open()
            ->with('task.category')
            ->get()
            ->groupBy(fn (TaskInstance $task_instance): string => (string) $task_instance->task?->category_id)
            ->map(function (Collection $instances) use ($now, $day_ends_at, $stale_before): array {
                $category = $instances->first()->task?->category;
                $overdue = $instances->filter(fn (TaskInstance $instance): bool => $instance->due_at?->lessThan($now) ?? false);
                $due_today = $instances->filter(fn (TaskInstance $instance): bool => $instance->due_at !== null
                    && $instance->due_at->greaterThanOrEqualTo($now)
                    && $instance->due_at->lessThanOrEqualTo($day_ends_at));
                $is_stale = $overdue->contains(fn (TaskInstance $instance): bool => $instance->due_at->lessThan($stale_before));

                return [
                    'category_id' => $category?->id,
                    'category_icon' => $category?->icon,
                    'category_name' => $category?->name,
                    'open' => $instances->count(),
                    'due_today' => $due_today->count(),
                    'overdue' => $overdue->count(),
                    'mess_level' => match (true) {
                        $overdue->count() >= 2 || $is_stale => self::MAX_MESS_LEVEL,
                        $overdue->count() === 1 => 2,
                        $due_today->isNotEmpty() => 1,
                        default => 0,
                    },
                ];
            })
            ->sortBy('category_id')
            ->values();
    }

    /**
     * @param  array{starts_at: string, ends_at: string, total_points: int, target_points: int}  $cycle
     */
    private function isBehindPace(array $cycle): bool
    {
        $starts_at = CarbonImmutable::parse($cycle['starts_at']);
        $length = $starts_at->diffInSeconds(CarbonImmutable::parse($cycle['ends_at']));
        if ($length <= 0) {
            return false;
        }
        $elapsed_fraction = min(1, max(0, $starts_at->diffInSeconds(CarbonImmutable::now()) / $length));

        return $cycle['total_points'] < $cycle['target_points'] * $elapsed_fraction * WeeklyPointGoal::TOLERANCE;
    }

    /**
     * Every member, children included, in joining order, so the client places them the same way every time.
     *
     * @return list<array{user_id: int, name: string, role: string, character: string, is_me: bool}>
     */
    private function members(User $user, Household $household): array
    {
        return $household->householdUsers()
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (HouseholdUser $household_user): array => [
                'user_id' => $household_user->user_id,
                'name' => $household_user->user->name,
                'role' => $household_user->role->value,
                'character' => $household_user->user->houseCharacter()->value,
                'is_me' => $household_user->user_id === $user->id,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function asController(#[CurrentUser] User $user, Household $household): array
    {
        return $this->handle($user, $household);
    }
}
