<?php

namespace App\Actions\Household;

use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Http\Requests\UpdateHouseholdSettingsRequest;
use App\Models\Household;
use App\Models\WeeklyPointGoal;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('update', 'household')]
class UpdateHouseholdSettingsAction
{
    use AsAction;

    /**
     * When the goal period changes, the open goals of the old, unfinished period are dropped without settlement or penalty,
     * because they would overlap the new period and never be closed. The goals of the new period are calculated right away.
     *
     * @param  array{reset_period: string, reset_day_of_week?: int|null, reset_day_of_month?: int|null}  $data
     */
    public function handle(Household $household, array $data): Household
    {
        $household->setData('reset.period', $data['reset_period']);

        if (isset($data['reset_day_of_week'])) {
            $household->setData('reset.day_of_week', (int) $data['reset_day_of_week']);
        }

        if (isset($data['reset_day_of_month'])) {
            $household->setData('reset.day_of_month', (int) $data['reset_day_of_month']);
        }

        if (! $household->isDirty('settings')) {
            return $household;
        }

        DB::transaction(function () use ($household): void {
            $household->save();

            WeeklyPointGoal::query()
                ->where('household_id', $household->id)
                ->whereNull('closed_at')
                ->whereDate('week_starts_at', '!=', WeeklyPointGoal::weekDate(WeeklyPointGoal::weekStartsAt(null, $household)))
                ->delete();

            RecalculateWeeklyPointGoalsAction::run($household);
        });

        return $household;
    }

    /**
     * @return array{household: Household, message: string}
     */
    public function asController(UpdateHouseholdSettingsRequest $request, Household $household): array
    {
        return ['household' => $this->handle($household, $request->validated()), 'message' => __('app.success_action')];
    }
}
