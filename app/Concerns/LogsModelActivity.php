<?php

namespace App\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Logs the changes of the model's fillable attributes into the activity log (spatie/laravel-activitylog).
 */
trait LogsModelActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
