<?php

namespace App\Enums;

/**
 * The animal that represents the user in the household's house view. The client has a sprite for each,
 * see flatshare-client/scripts/house-assets (Kenney Cube Pets).
 */
enum CharacterEnum: string
{
    case CAT = 'cat';
    case DOG = 'dog';
    case BUNNY = 'bunny';
    case FOX = 'fox';
    case PANDA = 'panda';
    case PENGUIN = 'penguin';
    case KOALA = 'koala';
    case PIG = 'pig';
    case MONKEY = 'monkey';
    case LION = 'lion';
    case TIGER = 'tiger';
    case POLAR = 'polar';
    case CHICK = 'chick';
    case PARROT = 'parrot';
    case BEE = 'bee';
    case BEAVER = 'beaver';
    case DEER = 'deer';
    case ELEPHANT = 'elephant';
    case GIRAFFE = 'giraffe';
    case COW = 'cow';
    case HOG = 'hog';
    case CRAB = 'crab';
    case CATERPILLAR = 'caterpillar';

    /**
     * The character of a user who has not chosen one: always the same for the user.
     */
    public static function defaultFor(int $user_id): self
    {
        $cases = self::cases();

        return $cases[$user_id % count($cases)];
    }
}
