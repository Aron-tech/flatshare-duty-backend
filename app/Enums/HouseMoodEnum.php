<?php

namespace App\Enums;

/**
 * The household's mood in the house view, from the 0-100 mood score, see GetHouseStateAction.
 */
enum HouseMoodEnum: string
{
    case HAPPY = 'happy';
    case CONTENT = 'content';
    case GRUMPY = 'grumpy';
    case SAD = 'sad';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 80 => self::HAPPY,
            $score >= 55 => self::CONTENT,
            $score >= 30 => self::GRUMPY,
            default => self::SAD,
        };
    }
}
