<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\Attributes\Translatable;
use Spatie\Translatable\HasTranslations;

#[Translatable('name')]
#[Fillable(['name', 'icon', 'color', 'sort_order'])]
class Category extends Model
{
    use HasTranslations;

    public const string CACHE_KEY = 'categories';

    protected function casts(): array
    {
        return [
            'sort_order' => 'int',
        ];
    }

    public static function invalidateCache(): void
    {
        cache()->forget(self::CACHE_KEY);
    }
}
