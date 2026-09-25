<?php

use App\Enums\PointTransactionType;
use App\Enums\RoleEnum;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function householdRewardUser(): User
{
    return User::findOrFail(DB::table('users')->insertGetId([
        'workos_id' => 'user_'.uniqid(),
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => uniqid().'@example.com',
        'avatar' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
}

function householdRewardTask(Household $household, array $attributes = []): Task
{
    return Task::withoutEvents(fn () => Task::create(array_merge([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => 'Task '.uniqid(),
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'base_points' => 100,
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
        'max_user' => 1,
    ], $attributes)));
}

function householdReward(Household $household, User $user): Reward
{
    return $household->rewards()->create(['user_id' => $user->id, 'name' => 'Pizza', 'description' => 'Pizza night', 'points_cost' => 50]);
}

beforeEach(function () {
    $this->admin = householdRewardUser();
    $this->child = householdRewardUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->admin->id]);
    $this->household->users()->attach($this->admin->id, ['role' => RoleEnum::ADMIN]);
    $this->household->users()->attach($this->child->id, ['role' => RoleEnum::CHILD]);
    $this->url = "/api/households/{$this->household->id}/rewards";
});

it('lets every member create a reward with its difficulty', function () {
    householdRewardTask($this->household);
    Sanctum::actingAs($this->child);

    $this->postJson($this->url, ['name' => 'Cinema', 'points_cost' => 150])
        ->assertOk()
        ->assertJsonPath('reward.user_id', $this->child->id)
        ->assertJsonPath('reward.is_active', true)
        ->assertJsonPath('difficulty.difficulty', 'hard')
        ->assertJsonPath('difficulty.weeks_needed', 3);

    expect(Reward::sole()->household_id)->toBe($this->household->id);
});

it('does not let outsiders create a reward', function () {
    Sanctum::actingAs(householdRewardUser());

    $this->postJson($this->url, ['name' => 'Cinema', 'points_cost' => 150])->assertForbidden();
});

it('validates the reward', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson($this->url, ['name' => 'X', 'points_cost' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'points_cost']);
});

it('calculates the difficulty from the weekly points of the recurring tasks', function (int $points_cost, string $difficulty) {
    householdRewardTask($this->household);
    Sanctum::actingAs($this->admin);

    $this->getJson("{$this->url}/difficulty?points_cost={$points_cost}")
        ->assertOk()
        ->assertJsonPath('weekly_points_per_member', 50)
        ->assertJsonPath('average_task_points', 100)
        ->assertJsonPath('difficulty', $difficulty);
})->with([
    [50, 'easy'],
    [100, 'medium'],
    [200, 'hard'],
    [201, 'very_hard'],
]);

it('falls back to the average task points without recurring tasks', function () {
    householdRewardTask($this->household, ['is_recurring' => false, 'recurrence_unit' => null, 'recurrence_interval' => null, 'base_points' => 20]);
    Sanctum::actingAs($this->admin);

    $this->getJson("{$this->url}/difficulty?points_cost=100")
        ->assertOk()
        ->assertJsonPath('weeks_needed', null)
        ->assertJsonPath('tasks_needed', 5)
        ->assertJsonPath('difficulty', 'medium');
});

it('has no difficulty without tasks', function () {
    Sanctum::actingAs($this->admin);

    $this->getJson("{$this->url}/difficulty?points_cost=100")
        ->assertOk()
        ->assertJsonPath('difficulty', null);

    $this->getJson("{$this->url}/difficulty")->assertUnprocessable();
});

it('lists the rewards with their difficulty', function () {
    householdRewardTask($this->household);
    householdReward($this->household, $this->admin);
    Sanctum::actingAs($this->child);

    $this->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('rewards.0.difficulty.difficulty', 'easy');
});

it('lets only the creator update a reward', function () {
    $reward = householdReward($this->household, $this->admin);
    $child_reward = householdReward($this->household, $this->child);

    Sanctum::actingAs($this->child);
    $this->putJson("{$this->url}/{$reward->id}", ['points_cost' => 80])->assertForbidden();
    $this->putJson("{$this->url}/{$child_reward->id}", ['points_cost' => 80])->assertOk()->assertJsonPath('reward.points_cost', 80);

    Sanctum::actingAs($this->admin);
    $this->putJson("{$this->url}/{$child_reward->id}", ['is_active' => false])->assertForbidden();

    expect($child_reward->fresh())->points_cost->toBe(80)->is_active->toBeTrue()
        ->and($reward->fresh()->points_cost)->toBe(50);
});

it('marks the reward as being edited until it is saved or cancelled', function () {
    $reward = householdReward($this->household, $this->child);

    Sanctum::actingAs($this->admin);
    $this->postJson("{$this->url}/{$reward->id}/editing")->assertForbidden();

    Sanctum::actingAs($this->child);
    $this->postJson("{$this->url}/{$reward->id}/editing")->assertOk()->assertJsonPath('reward.is_editing', true);
    $this->putJson("{$this->url}/{$reward->id}", ['name' => 'Big pizza'])->assertOk()->assertJsonPath('reward.is_editing', false);

    $this->postJson("{$this->url}/{$reward->id}/editing")->assertOk();
    $this->deleteJson("{$this->url}/{$reward->id}/editing")->assertOk()->assertJsonPath('reward.is_editing', false);
});

it('releases the editing after the timeout', function () {
    $stale_reward = householdReward($this->household, $this->admin);
    $stale_reward->update(['is_editing' => true, 'editing_started_at' => now()->subMinutes(Reward::EDITING_TIMEOUT_MINUTES + 1)]);
    $fresh_reward = householdReward($this->household, $this->admin);
    $fresh_reward->update(['is_editing' => true, 'editing_started_at' => now()]);

    Sanctum::actingAs($this->child);
    $this->getJson($this->url)
        ->assertJsonPath('rewards.0.is_editing', false)
        ->assertJsonPath('rewards.1.is_editing', true);

    $this->artisan('rewards:release-stale-editing')->assertSuccessful();

    expect($stale_reward->fresh()->is_editing)->toBeFalse()
        ->and($fresh_reward->fresh()->is_editing)->toBeTrue();
});

it('redeems a reward from the points balance', function () {
    $reward = householdReward($this->household, $this->admin);
    $reward->update(['stock_quantity' => 1]);
    $this->household->householdUsers()->where('user_id', $this->child->id)->update(['points_balance' => 120]);
    Sanctum::actingAs($this->child);

    $this->postJson("{$this->url}/{$reward->id}/redeem")->assertOk()->assertJsonPath('points_balance', 70);
    $this->postJson("{$this->url}/{$reward->id}/redeem")->assertForbidden();

    expect($reward->fresh()->stock_quantity)->toBe(0)
        ->and(RewardRedemption::sole())->user_id->toBe($this->child->id)->points_spent->toBe(50)
        ->and(PointTransaction::sole()->type)->toBe(PointTransactionType::REWARD_REDEMPTION);
});

it('does not let the creator redeem their own reward', function () {
    $reward = householdReward($this->household, $this->admin);
    $this->household->householdUsers()->where('user_id', $this->admin->id)->update(['points_balance' => 500]);
    Sanctum::actingAs($this->admin);

    $this->postJson("{$this->url}/{$reward->id}/redeem")
        ->assertForbidden()
        ->assertJsonPath('message', __('app.reward_own_not_redeemable'));
});

it('does not redeem a reward being edited or without enough points', function () {
    $reward = householdReward($this->household, $this->admin);
    $reward->update(['is_editing' => true, 'editing_started_at' => now()]);
    $this->household->householdUsers()->where('user_id', $this->child->id)->update(['points_balance' => 10]);
    Sanctum::actingAs($this->child);

    $this->postJson("{$this->url}/{$reward->id}/redeem")->assertForbidden()->assertJsonPath('message', __('app.reward_being_edited'));

    $reward->update(['is_editing' => false, 'editing_started_at' => null]);
    $this->postJson("{$this->url}/{$reward->id}/redeem")->assertForbidden()->assertJsonPath('message', __('app.reward_not_enough_points'));

    expect(RewardRedemption::count())->toBe(0);
});

it('lets only the creator or an admin delete a reward of the household', function () {
    $reward = householdReward($this->household, $this->admin);
    $other_household = Household::create(['name' => 'Other', 'join_code' => '0000000002', 'created_by' => $this->admin->id]);
    $other_reward = householdReward($other_household, $this->admin);

    Sanctum::actingAs($this->child);
    $this->deleteJson("{$this->url}/{$reward->id}")->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->deleteJson("{$this->url}/{$other_reward->id}")->assertForbidden();
    $this->deleteJson("{$this->url}/{$reward->id}")->assertOk();

    expect(Reward::pluck('id')->all())->toBe([$other_reward->id]);
});
