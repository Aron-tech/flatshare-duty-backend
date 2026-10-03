<?php

use App\Enums\HouseRoomEnum;
use App\Enums\PointTransactionType;
use App\Enums\RoleEnum;
use App\Models\Household;
use App\Models\HouseholdRoom;
use App\Models\PointTransaction;
use App\Models\RoomContribution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function roomUser(string $first_name): User
{
    return User::findOrFail(DB::table('users')->insertGetId([
        'workos_id' => 'user_'.uniqid(),
        'first_name' => $first_name,
        'last_name' => 'User',
        'email' => uniqid().'@example.com',
        'avatar' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
}

function giveRoomPoints(Household $household, User $user, int $points): void
{
    $household->householdUsers()->where('user_id', $user->id)->update(['points_balance' => $points]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Europe/Budapest'));
    $this->user = roomUser('Me');
    $this->other = roomUser('Other');
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000002', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::ADMIN]);
    $this->household->users()->attach($this->other->id, ['role' => RoleEnum::USER]);
    Sanctum::actingAs($this->user);
});

it('lists every extra room as locked with nothing collected', function () {
    $rooms = $this->getJson("/api/households/{$this->household->id}/house")->assertOk()->json('rooms');

    expect($rooms)->toHaveCount(count(HouseRoomEnum::cases()))
        ->and($rooms[0])->toMatchArray([
            'key' => 'kitchen',
            'price' => HouseRoomEnum::KITCHEN->price(),
            'collected' => 0,
            'unlocked' => false,
            'zones' => ['kitchen', 'trash'],
            'contributors' => [],
        ]);
});

it('moves the contributed points from the member into the room pot', function () {
    giveRoomPoints($this->household, $this->user, 150);

    $this->postJson("/api/households/{$this->household->id}/house/rooms/kitchen/contribute", ['amount' => 100])
        ->assertOk()
        ->assertJsonPath('points_balance', 50)
        ->assertJsonPath('room.collected', 100)
        ->assertJsonPath('room.unlocked', false)
        ->assertJsonPath('room.contributors.0', ['user_id' => $this->user->id, 'amount' => 100]);

    $transaction = PointTransaction::query()->where('type', PointTransactionType::ROOM_CONTRIBUTION)->sole();
    expect($transaction->amount)->toBe(100)
        ->and($transaction->balance_after)->toBe(50)
        ->and(RoomContribution::query()->sole()->point_transaction_id)->toBe($transaction->id);
});

it('unlocks the room once the members together reach its price and only takes what is missing', function () {
    $price = HouseRoomEnum::BATHROOM->price();
    giveRoomPoints($this->household, $this->user, $price);
    giveRoomPoints($this->household, $this->other, $price);

    $this->postJson("/api/households/{$this->household->id}/house/rooms/bathroom/contribute", ['amount' => $price - 50])->assertOk();

    Sanctum::actingAs($this->other);
    $this->postJson("/api/households/{$this->household->id}/house/rooms/bathroom/contribute", ['amount' => 200])
        ->assertOk()
        ->assertJsonPath('points_balance', $price - 50)
        ->assertJsonPath('room.collected', $price)
        ->assertJsonPath('room.unlocked', true);

    expect(HouseholdRoom::query()->sole()->unlocked_at)->not->toBeNull();

    $this->postJson("/api/households/{$this->household->id}/house/rooms/bathroom/contribute", ['amount' => 10])->assertForbidden();
});

it('refuses a contribution above the spendable points', function () {
    giveRoomPoints($this->household, $this->user, 30);

    $this->postJson("/api/households/{$this->household->id}/house/rooms/kitchen/contribute", ['amount' => 31])->assertForbidden();

    expect(PointTransaction::query()->count())->toBe(0)
        ->and(HouseholdRoom::query()->count())->toBe(0);
});

it('validates the room and the amount', function () {
    $this->postJson("/api/households/{$this->household->id}/house/rooms/attic/contribute", ['amount' => 10])->assertNotFound();
    $this->postJson("/api/households/{$this->household->id}/house/rooms/kitchen/contribute", ['amount' => 0])->assertUnprocessable();
});

it('does not let outsiders contribute', function () {
    Sanctum::actingAs(roomUser('Stranger'));

    $this->postJson("/api/households/{$this->household->id}/house/rooms/kitchen/contribute", ['amount' => 10])->assertForbidden();
});
