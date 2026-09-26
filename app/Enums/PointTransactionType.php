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
    case REWARD_REFUND = 'reward_refund';
    case MISSED_TASK_PENALTY = 'missed_task_penalty';
    case PENALTY_TASK_COMPLETION = 'penalty_task_completion';
    case WEEKLY_GOAL_SETTLEMENT = 'weekly_goal_settlement';

    /**
     * The points earned by doing tasks, they count towards the weekly goal.
     * A penalty task pays the points above the shortfall it covers, see TaskInstanceUser::payablePoints().
     *
     * @return list<self>
     */
    public static function earnedTypes(): array
    {
        return [self::TASK_COMPLETION, self::PENALTY_TASK_COMPLETION];
    }
}
