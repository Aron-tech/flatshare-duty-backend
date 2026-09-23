<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\Attributes\Translatable;
use Spatie\Translatable\HasTranslations;

#[Translatable('name', 'description')]
#[Fillable(['name', 'description', 'category_id', 'icon', 'default_duration_minutes', 'default_base_points'])]
class TaskTemplate extends Model
{
    use HasTranslations;

    public const string CACHE_KEY = 'task_templates';

    public static function invalidateCache(): void
    {
        cache()->forget(self::CACHE_KEY);
    }
}
