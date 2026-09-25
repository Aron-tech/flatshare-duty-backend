<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Enums\RoleEnum;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    public function getNameAttribute(): string
    {
        return $this->nickname ?? $this->getFullName();
    }

    public function getFullName(): string
    {
        return $this->language === LanguageEnum::HUNGARIAN ? "{$this->last_name} {$this->first_name}" : "{$this->first_name} {$this->last_name}";
    }

    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'household_users');
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    public function isAdminOf(Household|int $household): bool
    {
        $household_id = $household instanceof Household ? $household->id : $household;

        return $this->households()->where('household_id', $household_id)->wherePivot('role', RoleEnum::ADMIN)->exists();
    }
}
