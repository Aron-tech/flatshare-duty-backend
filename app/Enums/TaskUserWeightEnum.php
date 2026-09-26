<?php

namespace App\Enums;

enum TaskUserWeightEnum: string
{
    case HATE = 'hate';
    case DISLIKE = 'dislike';
    case NEUTRAL = 'neutral';
    case LIKE = 'like';
    case LOVE = 'love';

    /**
     * The household's common price of a task is the average of these, see CalculateTaskPointsAction::weightMultiplier(),
     * so one member moves the price by (multiplier - 1) / the number of members.
     */
    public function multiplier(): float
    {
        return match ($this) {
            self::HATE => 1.25,
            self::DISLIKE => 1.10,
            self::NEUTRAL => 1.00,
            self::LIKE => 0.90,
            self::LOVE => 0.75,
        };
    }

    public function getName(): string
    {
        return __('enum.task_user_weight_'.$this->value);
    }
}
