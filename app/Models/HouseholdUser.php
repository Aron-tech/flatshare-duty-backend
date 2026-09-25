<?php

namespace App\Models;

use App\Concerns\LogsModelActivity;
use App\Enums\RoleEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['household_id', 'user_id', 'role', 'points_balance'])]
/**
 * Custom pivot of the household members, so attaching and detaching members fires model events (activity log).
 */
class HouseholdUser extends Pivot
{
    use LogsModelActivity {
        getActivitylogOptions as defaultActivitylogOptions;
    }

    protected $table = 'household_users';

    public $incrementing = true;

    /**
     * The points balance changes with every completed task, those are logged as point transactions.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->defaultActivitylogOptions()->dontLogIfAttributesChangedOnly(['points_balance']);
    }

    protected function casts(): array
    {
        return [
            'points_balance' => 'int',
            'role' => RoleEnum::class,
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
