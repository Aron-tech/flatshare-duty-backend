<?php

namespace App\Models;

use App\Actions\CalculateHouseholdMinPointsAction;
use App\Concerns\LogsModelActivity;
use App\Observers\HouseholdObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Random\RandomException;

#[Fillable(['name', 'join_code', 'created_by'])]
#[ObservedBy([HouseholdObserver::class])]
class Household extends Model
{
    use LogsModelActivity;
    use SoftDeletes;

    /**
     * @throws RandomException
     */
    public static function generateUniqueJoinCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        } while (self::withTrashed()->where('join_code', $code)->exists());

        return $code;
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
}
