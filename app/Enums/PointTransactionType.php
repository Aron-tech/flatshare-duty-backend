<?php

namespace App\Enums;

enum PointTransactionType: string
{
    case TASK_COMPLETION = 'task_completion';
    case DELEGATION_ESCROW_LOCK = 'delegation_escrow_lock';
    case DELEGATION_ESCROW_REFUND = 'delegation_escrow_refund';
    case DELEGATION_PAYOUT = 'delegation_payout';
    case DELEGATION_PENALTY_BURN = 'delegation_penalty_burn';
    case REWARD_REDEMPTION = 'reward_redemption';
    case MISSED_TASK_PENALTY = 'missed_task_penalty';
}
