<?php

namespace App\Models;

use App\Enums\HouseRoomEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An extra room of the household's house view: the points collected so far, unlocked_at is set once they reach the price.
 * The row is created by the first contribution, see ContributeToRoomAction.
 */
#[Fillable(['household_id', 'room', 'collected', 'unlocked_at'])]
class HouseholdRoom extends Model
{
    protected function casts(): array
    {
        return [
            'room' => HouseRoomEnum::class,
            'collected' => 'int',
            'unlocked_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(RoomContribution::class);
    }
}
