<?php

namespace App\Models;

use App\Enums\ResetPeriodEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tag heti vagy havi (a háztartás beállítása szerinti) minimum pontszáma a háztartásban, az időszak lezárásakor a szerzett ponttal és a hiánnyal együtt.
 */
#[Fillable(['household_id', 'user_id', 'week_starts_at', 'target_points', 'earned_points', 'shortfall_points', 'reminded_at', 'closed_at'])]
class WeeklyPointGoal extends Model
{
    /**
     * No penalty when the member earned at least this part of the minimum points.
     */
    public const float TOLERANCE = 0.9;

    protected function casts(): array
    {
        return [
            'week_starts_at' => 'immutable_date',
            'target_points' => 'int',
            'earned_points' => 'int',
            'shortfall_points' => 'int',
            'reminded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * The start of the household's goal period containing the moment, in UTC, so it can be compared with the stored timestamps.
     * The period starts at 00:00 in the week timezone (see config app.week_timezone) on the household's reset day,
     * and it is weekly or monthly, see Household::resetPeriod(). Without a household it is a week starting on Monday.
     */
    public static function weekStartsAt(?CarbonInterface $at = null, ?Household $household = null): CarbonImmutable
    {
        $local = CarbonImmutable::instance($at ?? now())->setTimezone(config('app.week_timezone'))->startOfDay();

        if ($household?->resetPeriod() === ResetPeriodEnum::MONTHLY) {
            $starts_at = $local->startOfMonth()->addDays($household->resetDayOfMonth() - 1);
            if ($starts_at->greaterThan($local)) {
                $starts_at = $local->startOfMonth()->subMonthNoOverflow()->addDays($household->resetDayOfMonth() - 1);
            }

            return $starts_at->utc();
        }

        $reset_day = $household?->resetDayOfWeek() ?? 1;

        return $local->subDays(($local->dayOfWeekIso - $reset_day + 7) % 7)->utc();
    }

    /**
     * The end of the period starting at the given moment, in UTC. A period with a daylight saving change is an hour shorter or longer.
     */
    public static function weekEndsAt(CarbonInterface $week_starts_at, ?Household $household = null): CarbonImmutable
    {
        $local = CarbonImmutable::instance($week_starts_at)->setTimezone(config('app.week_timezone'));

        return ($household?->resetPeriod() === ResetPeriodEnum::MONTHLY ? $local->addMonthNoOverflow() : $local->addWeek())->utc();
    }

    /**
     * The date of the day the period starts on, as stored in week_starts_at.
     */
    public static function weekDate(CarbonInterface $week_starts_at): string
    {
        return CarbonImmutable::instance($week_starts_at)->setTimezone(config('app.week_timezone'))->toDateString();
    }

    /**
     * The start of the goal's period in UTC, see weekStartsAt().
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->week_starts_at->toDateString(), config('app.week_timezone'))->utc();
    }

    public function endsAt(): CarbonImmutable
    {
        return self::weekEndsAt($this->startsAt(), $this->household);
    }

    /**
     * The task completion points the user earned in the household during the goal's week.
     */
    public function calculateEarnedPoints(): int
    {
        return (int) PointTransaction::query()
            ->earnedBy($this->household_id, $this->user_id)
            ->createdBetween($this->startsAt(), $this->endsAt())
            ->sum('amount');
    }

    /**
     * The part of the earned points that covers the minimum, deducted when the week is closed. Only the extra points are kept.
     */
    public function settledPoints(int $earned_points): int
    {
        return max(0, min($earned_points, $this->target_points));
    }

    /**
     * The shortfall below the tolerated part of the minimum, a penalty task has to cover it.
     * It grows from zero at the tolerance, so missing it by a little costs a little.
     */
    public function penaltyPoints(int $earned_points): int
    {
        return max(0, (int) ceil($this->target_points * self::TOLERANCE) - $earned_points);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A hiány miatt kiosztott büntető feladatok.
     */
    public function penaltyTaskInstanceUsers(): HasMany
    {
        return $this->hasMany(TaskInstanceUser::class);
    }
}
