<?php

namespace App\Concerns;

/**
 * API válaszban a fordítható mezők az aktuális nyelven, stringként jelennek meg
 * (a spatie/laravel-translatable alapból az összes fordítást visszaadná).
 * A `HasTranslations` traittel együtt használandó.
 */
trait SerializesTranslationsInLocale
{
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        foreach ($this->getTranslatableAttributes() as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = $this->getTranslation($field, app()->getLocale());
            }
        }

        return $attributes;
    }
}
