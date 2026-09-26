<?php

namespace App\Enums;

enum TaskOfferStatusEnum: string
{
    /** Waiting for a member to take it over, the points are held in escrow. */
    case OPEN = 'open';
    /** Taken over, the escrow is paid out when the taker completes the task. */
    case ACCEPTED = 'accepted';
    case COMPLETED = 'completed';
    /** Withdrawn by the offerer, or the offerer completed the task, the escrow is refunded. */
    case CANCELLED = 'cancelled';
    /** Nobody took it over by the due date, the escrow is refunded. */
    case EXPIRED = 'expired';
    /** The taker missed the task: the penalty part of the escrow is burned, the rest is refunded. */
    case FAILED = 'failed';
}
