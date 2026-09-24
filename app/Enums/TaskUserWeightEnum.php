<?php

namespace App\Enums;

enum TaskUserWeightEnum: string
{
    case HATE = 'hate';
    case DISLIKE = 'dislike';
    case NEUTRAL = 'neutral';
    case LIKE = 'like';
    case LOVE = 'love';

    public function multiplier(): float
    {
        return match ($this) {
            self::HATE => 1.15,
            self::DISLIKE => 1.05,
            self::NEUTRAL => 1.00,
            self::LIKE => 0.95,
            self::LOVE => 0.85,
        };
    }

    public function getName(): string
    {
        return __('enum.task_user_weight_'.$this->value);
    }
}
