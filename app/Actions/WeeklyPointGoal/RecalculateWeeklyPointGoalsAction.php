<?php

namespace App\Actions\WeeklyPointGoal;

use App\Actions\CalculateHouseholdMinPointsAction;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\WeeklyPointGoal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class RecalculateWeeklyPointGoalsAction
{
    use AsAction;

    /**
     * Updates the members' minimum points of the week (the current one by default) from the household's tasks.
     * A task or a member added during the week only counts for the remaining part of the week.
     * Closed goals are left untouched, the open goals of former members are removed.
     *
     * @return Collection<int, WeeklyPointGoal> keyed by user id
     */
    public function handle(Household $household, ?CarbonImmutable $week_starts_at = null): Collection
    {
        $week_starts_at ??= CarbonImmutable::now()->startOfWeek();
        $calculate_min_points = CalculateHouseholdMinPointsAction::make();
        $points_per_member = $calculate_min_points->handle($household, $week_starts_at);
        $household_users = $household->householdUsers()->get();

        DB::transaction(function () use ($household, $week_starts_at, $calculate_min_points, $points_per_member, $household_users) {
            $this->goalsOfWeek($household, $week_starts_at)
                ->whereNull('closed_at')
                ->whereNotIn('user_id', $household_users->pluck('user_id'))
                ->delete();

            $now = now();
            WeeklyPointGoal::insertOrIgnore($household_users->map(fn (HouseholdUser $household_user): array => [
                'household_id' => $household->id,
                'user_id' => $household_user->user_id,
                'week_starts_at' => $week_starts_at->toDateString(),
                'target_points' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            foreach ($household_users as $household_user) {
                $this->goalsOfWeek($household, $week_starts_at)
                    ->where('user_id', $household_user->user_id)
                    ->whereNull('closed_at')
                    ->update([
                        'target_points' => (int) round($points_per_member * $calculate_min_points->activeFraction($household_user->created_at, $week_starts_at)),
                        'updated_at' => $now,
                    ]);
            }
        });

        return $this->goalsOfWeek($household, $week_starts_at)->get()->keyBy('user_id');
    }

    /**
     * The goals of the current week, calculated first when a member does not have one yet.
     *
     * @return Collection<int, WeeklyPointGoal> keyed by user id
     */
    public function currentGoals(Household $household): Collection
    {
        $goals = $this->goalsOfWeek($household, CarbonImmutable::now()->startOfWeek())->get()->keyBy('user_id');
        $member_ids = $household->householdUsers()->pluck('user_id');

        if ($member_ids->diff($goals->keys())->isNotEmpty()) {
            return $this->handle($household);
        }

        return $goals->whereIn('user_id', $member_ids);
    }

    /**
     * @return Builder<WeeklyPointGoal>
     */
    private function goalsOfWeek(Household $household, CarbonImmutable $week_starts_at): Builder
    {
        return WeeklyPointGoal::query()
            ->where('household_id', $household->id)
            ->whereDate('week_starts_at', $week_starts_at->toDateString());
    }
}
