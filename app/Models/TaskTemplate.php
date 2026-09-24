<?php

namespace App\Models;

use App\Observers\TaskTemplateObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\Attributes\Translatable;
use Spatie\Translatable\HasTranslations;

#[Translatable('name', 'description')]
#[Fillable(['name', 'description', 'category_id', 'icon', 'duration_minutes', 'difficulty', 'base_points', 'max_user'])]
#[ObservedBy([TaskTemplateObserver::class])]
class TaskTemplate extends Model
{
    use HasTranslations;

    public const string CACHE_KEY = 'task_templates';

    public static function invalidateCache(): void
    {
        cache()->forget(self::CACHE_KEY);
    }

    public function calculateBasePoints(): self
    {
        $this->base_points = round($this->difficulty * $this->duration_minutes);

        return $this;
    }
}
