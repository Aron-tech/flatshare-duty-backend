<?php

namespace App\Enums;

enum LanguageEnum: string
{
    case HUNGARIAN = 'hu';
    case ENGLISH = 'en';

    public function getName(): string
    {
        return __('enum.language.'.$this->value);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function getOptions(): array
    {
        return array_map(fn (self $role): array => ['value' => $role->value, 'label' => $role->getName()], self::cases());
    }

    public static function fromLocale(string $locale): ?self
    {
        return self::tryFrom(substr($locale, 0, 2));
    }
}
