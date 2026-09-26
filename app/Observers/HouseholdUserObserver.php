<?php

namespace App\Observers;

use App\Actions\HouseholdReward\RefundMemberRewardRedemptionsAction;
use App\Actions\HouseholdUser\RecordHouseholdMemberDepartureAction;
use App\Actions\HouseholdUser\ReleaseMemberAssignmentsAction;
use App\Actions\HouseholdUser\ReleaseMemberClaimsAction;
use App\Models\Household;
use App\Models\HouseholdMemberDeparture;
use App\Models\HouseholdUser;
use App\Models\Reward;
use Illuminate\Support\Facades\Auth;

/**
 * Cleans up after a member who leaves or is removed from the household. Deleting the whole household
 * removes its members too, that is skipped: the household is already trashed by then, see HouseholdObserver.
 */
class HouseholdUserObserver
{
    /**
     * A returning member's pending departure is dropped, the admins do not have to decide about their tasks anymore.
     */
    public function created(HouseholdUser $household_user): void
    {
        HouseholdMemberDeparture::query()
            ->unresolved()
            ->where('household_id', $household_user->household_id)
            ->where('user_id', $household_user->user_id)
            ->update(['resolved_at' => now()]);
    }

    /**
     * Runs before the membership is gone, so the refunds of the member's cancelled offers can still be settled.
     * The member's open claims are released, the recurring tasks assigned to them freed,
     * the pending redemptions of their rewards refunded and their rewards deleted.
     */
    public function deleting(HouseholdUser $household_user): void
    {
        if (! $this->householdExists($household_user)) {
            return;
        }

        ReleaseMemberClaimsAction::run($household_user->household_id, $household_user->user_id);
        ReleaseMemberAssignmentsAction::run($household_user->household_id, $household_user->user_id);
        RefundMemberRewardRedemptionsAction::run($household_user->household_id, $household_user->user_id);

        Reward::query()
            ->where('household_id', $household_user->household_id)
            ->where('user_id', $household_user->user_id)
            ->each(fn (Reward $reward) => $reward->delete());
    }

    /**
     * The admins are notified and asked about the tasks created by the former member.
     */
    public function deleted(HouseholdUser $household_user): void
    {
        if (! $this->householdExists($household_user)) {
            return;
        }

        $removed_by = Auth::id() !== $household_user->user_id ? Auth::id() : null;
        RecordHouseholdMemberDepartureAction::run($household_user->household_id, $household_user->user_id, $removed_by);
    }

    private function householdExists(HouseholdUser $household_user): bool
    {
        return Household::query()->whereKey($household_user->household_id)->exists();
    }
}
