<?php

namespace App\Enums;

/**
 * The extra rooms of the house view that the members unlock together with their points.
 * The main room is always there; an unlocked room takes over its zones (the client maps task categories to zones),
 * so their mess shows up in the new room. The client builds each room, see flatshare-client/scripts/house-assets.
 */
enum HouseRoomEnum: string
{
    case KITCHEN = 'kitchen';
    case BATHROOM = 'bathroom';

    /**
     * The points the members have to collect together to unlock the room.
     */
    public function price(): int
    {
        return match ($this) {
            self::KITCHEN => 400,
            self::BATHROOM => 300,
        };
    }

    /**
     * The house zones that move into the room once it is unlocked.
     *
     * @return list<string>
     */
    public function zones(): array
    {
        return match ($this) {
            self::KITCHEN => ['kitchen', 'trash'],
            self::BATHROOM => ['bathroom', 'laundry'],
        };
    }
}
