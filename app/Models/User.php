<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Enums\RoleEnum;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Appends(['name'])]
#[Fillable(['workos_id', 'first_name', 'last_name', 'nickname', 'email', 'avatar', 'language'])]
class User extends Authenticatable
{
    use HasApiTokens;

    protected function casts(): array
    {
        return [
            'language' => LanguageEnum::class,
        ];
    }

    /**
     * The nickname, or the full name when the user has not set one.
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => $this->nickname ?? $this->getFullName());
    }

    public function getFullName(): string
    {
        return $this->language === LanguageEnum::HUNGARIAN ? "{$this->last_name} {$this->first_name}" : "{$this->first_name} {$this->last_name}";
    }

    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'household_users')->using(HouseholdUser::class)->withTimestamps();
    }

    public function householdUsers(): HasMany
    {
        return $this->hasMany(HouseholdUser::class);
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /**
     * The user's membership in the household, null when the user is not a member.
     */
    public function membershipOf(Household|int $household): ?HouseholdUser
    {
        return $this->membershipQuery($household)->first();
    }

    public function isMemberOf(Household|int $household): bool
    {
        return $this->membershipQuery($household)->exists();
    }

    public function isAdminOf(Household|int $household): bool
    {
        return $this->membershipQuery($household)->where('role', RoleEnum::ADMIN)->exists();
    }

    private function membershipQuery(Household|int $household): HasMany
    {
        return $this->householdUsers()->where('household_id', $household instanceof Household ? $household->getKey() : $household);
    }
}
