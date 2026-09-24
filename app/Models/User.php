<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Enums\RoleEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['workos_id', 'first_name', 'last_name', 'email', 'avatar', 'language'])]
class User extends Authenticatable
{
    use HasApiTokens;

    protected function casts(): array
    {
        return [
            'language' => LanguageEnum::class,
        ];
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
