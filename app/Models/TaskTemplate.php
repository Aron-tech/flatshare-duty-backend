<?php

namespace App\Models;

use App\Concerns\CalculatesBasePoints;
use App\Concerns\SerializesTranslationsInLocale;
use App\Enums\LanguageEnum;
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
    use CalculatesBasePoints;
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

    /**
     * The templates with their category, serialized in the current locale. They rarely change, so they are cached per locale
     * as plain arrays (the cache does not unserialize objects, see config cache.serializable_classes).
     *
     * @return list<array<string, mixed>>
     */
    public static function cachedInLocale(): array
    {
        return cache()->rememberForever(
            self::CACHE_KEY.'.'.app()->getLocale(),
            fn (): array => self::query()->with('category')->orderBy('id')->get()->toArray(),
        );
    }

    public static function invalidateCache(): void
    {
        foreach (LanguageEnum::cases() as $language) {
            cache()->forget(self::CACHE_KEY.'.'.$language->value);
        }
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
