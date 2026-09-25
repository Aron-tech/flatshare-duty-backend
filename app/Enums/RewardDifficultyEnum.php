<?php

namespace App\Enums;

enum RewardDifficultyEnum: string
{
    case EASY = 'easy';
    case MEDIUM = 'medium';
    case HARD = 'hard';
    case VERY_HARD = 'very_hard';

    /**
     * Based on how many weeks a member needs to earn the points by doing their share of the recurring tasks.
     */
    public static function fromWeeks(float $weeks): self
    {
        return match (true) {
            $weeks <= 1 => self::EASY,
            $weeks <= 2 => self::MEDIUM,
            $weeks <= 4 => self::HARD,
            default => self::VERY_HARD,
        };
    }

    /**
     * Fallback when the household has no recurring tasks: based on how many average tasks earn the points.
     */
    public static function fromTasks(int $tasks): self
    {
        return match (true) {
            $tasks <= 3 => self::EASY,
            $tasks <= 8 => self::MEDIUM,
            $tasks <= 20 => self::HARD,
            default => self::VERY_HARD,
        };
    }

    public function getName(): string
    {
        return __('enum.reward_difficulty.'.$this->value);
    }
}
