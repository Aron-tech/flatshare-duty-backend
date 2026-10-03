<?php

namespace App\Concerns;

/**
 * Serializes translatable fields as a string in the current locale (spatie/laravel-translatable returns all of them).
 * Use together with the `HasTranslations` trait.
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
