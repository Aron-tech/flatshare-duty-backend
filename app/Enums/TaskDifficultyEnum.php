<?php

namespace App\Enums;

enum TaskDifficultyEnum: string
{
    case EASY   = 'easy';
    case MEDIUM = 'medium';
    case HARD   = 'hard';

    public function multiplier(): float
    {
        return match ($this) {
            self::EASY   => 1.0,
            self::MEDIUM => 1.2,
            self::HARD   => 1.5,
        };
    }

    public function getName(): string
    {
        return __('enum.task_difficulty.'.$this->value);
    }

     /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function getOptions(): array
    {
        return array_map(fn (self $task_difficulty): array => ['value' => $task_difficulty->value, 'label' => $task_difficulty->getName()], self::cases());
    }
}
