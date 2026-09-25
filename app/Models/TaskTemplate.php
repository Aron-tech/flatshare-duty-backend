<?php

namespace App\Models;

use App\Concerns\SerializesTranslationsInLocale;
use App\Enums\TaskDifficultyEnum;
use App\Observers\TaskTemplateObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\Attributes\Translatable;
use Spatie\Translatable\HasTranslations;

#[Translatable('name', 'description')]
#[Fillable(['name', 'description', 'category_id', 'icon', 'duration_minutes', 'difficulty', 'base_points', 'max_user'])]
#[ObservedBy([TaskTemplateObserver::class])]
class TaskTemplate extends Model
{
    use HasTranslations;
    use SerializesTranslationsInLocale;

    public const string CACHE_KEY = 'task_templates';

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'int',
            'difficulty' => TaskDifficultyEnum::class,
            'base_points' => 'int',
            'max_user' => 'int',
        ];
    }

    public static function invalidateCache(): void
    {
        cache()->forget(self::CACHE_KEY);
    }

    public function calculateBasePoints(): self
    {
        $this->base_points = (int) round($this->difficulty->multiplier() * $this->duration_minutes);

        return $this;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
