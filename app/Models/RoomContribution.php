<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The points a member put towards an extra room of the house view.
 */
#[Fillable(['household_room_id', 'user_id', 'amount', 'point_transaction_id'])]
class RoomContribution extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'int',
        ];
    }

    public function householdRoom(): BelongsTo
    {
        return $this->belongsTo(HouseholdRoom::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pointTransaction(): BelongsTo
    {
        return $this->belongsTo(PointTransaction::class);
    }
}
