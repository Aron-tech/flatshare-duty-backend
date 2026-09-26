<?php

namespace App\Models;

use App\Actions\CalculateHouseholdMinPointsAction;
use App\Concerns\DataTrait;
use App\Concerns\LogsModelActivity;
use App\Enums\ResetPeriodEnum;
use App\Observers\HouseholdObserver;
use App\Policies\HouseholdPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Random\RandomException;

#[Fillable(['name', 'join_code', 'settings', 'created_by'])]
#[ObservedBy([HouseholdObserver::class])]
#[UsePolicy(HouseholdPolicy::class)]
class Household extends Model
{
    use DataTrait;
    use LogsModelActivity;
    use SoftDeletes;

    protected string $json_data_column = 'settings';

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * @throws RandomException
     */
    public static function generateUniqueJoinCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        } while (self::withTrashed()->where('join_code', $code)->exists());

        return $code;
    }

    public function resetPeriod(): ResetPeriodEnum
    {
        return ResetPeriodEnum::tryFrom((string) $this->getData('reset.period')) ?? ResetPeriodEnum::WEEKLY;
    }

    /**
     * ISO day of the week (1 = Monday, 7 = Sunday) on which a weekly reset happens.
     */
    public function resetDayOfWeek(): int
    {
        return (int) $this->getData('reset.day_of_week', 1);
    }

    /**
     * Day of the month (1-28) on which a monthly reset happens.
     */
    public function resetDayOfMonth(): int
    {
        return (int) $this->getData('reset.day_of_month', 1);
    }

    /**
     * The points every member has to earn in a week, calculated from the household's tasks.
     */
    protected function minPoints(): Attribute
    {
        return Attribute::get(fn (): int => CalculateHouseholdMinPointsAction::run($this));
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'household_users')->using(HouseholdUser::class)->withTimestamps();
    }

    public function householdUsers(): HasMany
    {
        return $this->hasMany(HouseholdUser::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    public function weeklyPointGoals(): HasMany
    {
        return $this->hasMany(WeeklyPointGoal::class);
    }

    public function taskInstances(): HasMany
    {
        return $this->hasMany(TaskInstance::class);
    }

    public function taskOffers(): HasMany
    {
        return $this->hasMany(TaskOffer::class);
    }

    public function rewardRedemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }

    public function memberDepartures(): HasMany
    {
        return $this->hasMany(HouseholdMemberDeparture::class);
    }
}
