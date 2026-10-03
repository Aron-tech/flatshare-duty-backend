<?php

namespace App\Actions\House;

use App\Actions\GetHouseholdUserAction;
use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Enums\HouseRoomEnum;
use App\Enums\PointTransactionType;
use App\Http\Requests\ContributeToRoomRequest;
use App\Models\Household;
use App\Models\HouseholdRoom;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\RoomContribution;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Every member, children included, can put points towards an extra room of the house view.
 */
#[Authorize('view', 'household')]
class ContributeToRoomAction
{
    use AsAction;

    /**
     * Moves the user's spendable points (see HouseholdUser::spendablePoints()) into the room's shared pot.
     * Only the points still missing are taken, and the room is unlocked once the pot reaches its price.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, HouseRoomEnum $room, int $amount): HouseholdRoom
    {
        $household_user = GetHouseholdUserAction::run($user, $household);

        RecalculateWeeklyPointGoalsAction::make()->currentGoals($household);

        return DB::transaction(function () use ($household, $household_user, $room, $amount): HouseholdRoom {
            HouseholdRoom::query()->insertOrIgnore([
                'household_id' => $household->id,
                'room' => $room->value,
                'collected' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $household_room = HouseholdRoom::query()
                ->where('household_id', $household->id)
                ->where('room', $room->value)
                ->lockForUpdate()
                ->firstOrFail();
            $household_user = HouseholdUser::query()->lockForUpdate()->findOrFail($household_user->id);

            if ($household_room->unlocked_at !== null) {
                throw new AuthorizationException(__('app.room_already_unlocked'));
            }

            $amount = min($amount, $room->price() - $household_room->collected);

            if ($household_user->spendablePoints() < $amount) {
                throw new AuthorizationException(__('app.room_not_enough_points'));
            }

            $household_user->decrement('points_balance', $amount);

            $point_transaction = PointTransaction::create([
                'household_id' => $household->id,
                'user_id' => $household_user->user_id,
                'amount' => $amount,
                'balance_after' => $household_user->points_balance,
                'type' => PointTransactionType::ROOM_CONTRIBUTION,
            ]);

            RoomContribution::create([
                'household_room_id' => $household_room->id,
                'user_id' => $household_user->user_id,
                'amount' => $amount,
                'point_transaction_id' => $point_transaction->id,
            ]);

            $household_room->collected += $amount;
            if ($household_room->collected >= $room->price()) {
                $household_room->unlocked_at = now();
            }
            $household_room->save();

            return $household_room;
        });
    }

    /**
     * @return array{points_balance: int, room: array{key: string, price: int, collected: int, unlocked: bool, zones: list<string>, contributors: list<array{user_id: int, amount: int}>}, message: string}
     */
    public function asController(ContributeToRoomRequest $request, #[CurrentUser] User $user, Household $household, HouseRoomEnum $room): array
    {
        $household_room = $this->handle($user, $household, $room, $request->integer('amount'));

        return [
            'points_balance' => GetHouseholdUserAction::run($user, $household)->points_balance,
            'room' => collect(GetHouseStateAction::make()->rooms($household))->firstWhere('key', $room->value),
            'message' => $household_room->unlocked_at !== null ? __('app.room_unlocked') : __('app.success_action'),
        ];
    }
}
