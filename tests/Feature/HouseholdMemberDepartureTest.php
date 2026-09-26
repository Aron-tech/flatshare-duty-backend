<?php

use App\Actions\HouseholdMemberDeparture\CloseStaleMemberDeparturesAction;
use App\Enums\PointTransactionType;
use App\Enums\RoleEnum;
use App\Enums\TaskAssignmentModeEnum;
use App\Jobs\SendExpoPushNotificationsJob;
use App\Models\Household;
use App\Models\HouseholdMemberDeparture;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\PushToken;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function departureUser(): User
{
    $user = User::findOrFail(DB::table('users')->insertGetId([
        'workos_id' => 'user_'.uniqid(),
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => uniqid().'@example.com',
        'avatar' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
    PushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken['.uniqid().']', 'platform' => 'ios']);

    return $user;
}

function departureTask(Household $household, User $creator, array $attributes = []): Task
{
    return Task::create([
        'household_id' => $household->id,
        'created_by' => $creator->id,
        'name' => 'Task '.uniqid(),
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'day',
        ...$attributes,
    ]);
}

function departureMembership(Household $household, User $user): HouseholdUser
{
    return HouseholdUser::where('household_id', $household->id)->where('user_id', $user->id)->sole();
}

beforeEach(function () {
    Queue::fake();
    $this->admin = departureUser();
    $this->other_admin = departureUser();
    $this->member = departureUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->admin->id]);
    $this->household->users()->attach($this->admin->id, ['role' => RoleEnum::ADMIN]);
    $this->household->users()->attach($this->other_admin->id, ['role' => RoleEnum::ADMIN]);
    $this->household->users()->attach($this->member->id, ['role' => RoleEnum::USER]);
    $this->url = "/api/households/{$this->household->id}";
});

it('deletes the rewards of a member who leaves but keeps the others', function () {
    $reward = $this->household->rewards()->create(['user_id' => $this->member->id, 'name' => 'Pizza', 'points_cost' => 50]);
    $admin_reward = $this->household->rewards()->create(['user_id' => $this->admin->id, 'name' => 'Movie', 'points_cost' => 50]);

    Sanctum::actingAs($this->member);
    $this->deleteJson("{$this->url}/leave")->assertOk();

    expect(Reward::pluck('id')->all())->toBe([$admin_reward->id])
        ->and($reward->fresh()->trashed())->toBeTrue();
});

it('does not let a former member edit or delete their reward', function () {
    $reward = $this->household->rewards()->create(['user_id' => $this->member->id, 'name' => 'Pizza', 'points_cost' => 50]);
    HouseholdUser::withoutEvents(fn () => departureMembership($this->household, $this->member)->delete());

    Sanctum::actingAs($this->member);
    $this->putJson("{$this->url}/rewards/{$reward->id}", ['points_cost' => 80])->assertForbidden();
    $this->postJson("{$this->url}/rewards/{$reward->id}/editing")->assertForbidden();
    $this->deleteJson("{$this->url}/rewards/{$reward->id}")->assertForbidden();

    expect($reward->fresh())->points_cost->toBe(50)->trashed()->toBeFalse();
});

it('frees the recurring tasks assigned to a member who leaves', function () {
    $fixed = departureTask($this->household, $this->admin, ['assignment_mode' => 'fixed', 'fixed_user_id' => $this->member->id]);
    $rotating = departureTask($this->household, $this->admin, ['assignment_mode' => 'rotating']);
    $rotating->rotations()->createMany([
        ['user_id' => $this->member->id, 'rotation_order' => 0],
        ['user_id' => $this->admin->id, 'rotation_order' => 1],
    ]);
    $alone = departureTask($this->household, $this->admin, ['assignment_mode' => 'rotating']);
    $alone->rotations()->create(['user_id' => $this->member->id, 'rotation_order' => 0]);
    $fixed->taskInstances()->sole()->claimFor($this->member->id);

    Sanctum::actingAs($this->member);
    $this->deleteJson("{$this->url}/leave")->assertOk();

    expect($fixed->fresh())->assignment_mode->toBe(TaskAssignmentModeEnum::NONE)->fixed_user_id->toBeNull()
        ->and($fixed->taskInstances()->sole()->taskInstanceUsers()->count())->toBe(0)
        ->and($rotating->fresh()->assignment_mode)->toBe(TaskAssignmentModeEnum::ROTATING)
        ->and($rotating->rotations()->pluck('user_id')->all())->toBe([$this->admin->id])
        ->and($alone->fresh()->assignment_mode)->toBe(TaskAssignmentModeEnum::NONE);
});

it('asks the admins about the tasks of a member who leaves', function () {
    departureTask($this->household, $this->member);

    Sanctum::actingAs($this->member);
    $this->deleteJson("{$this->url}/leave")->assertOk();

    $departure = HouseholdMemberDeparture::sole();
    expect($departure)->user_id->toBe($this->member->id)->removed_by->toBeNull()->resolved_at->toBeNull();

    Queue::assertPushed(SendExpoPushNotificationsJob::class, fn (SendExpoPushNotificationsJob $job) => count($job->tokens) === 2
        && $job->data['route'] === "/member-departures?household_id={$this->household->id}");
});

it('notifies the other admins when a member without tasks is removed', function () {
    Sanctum::actingAs($this->admin);
    $this->deleteJson('/api/household-users/'.departureMembership($this->household, $this->member)->id)->assertOk();

    expect(HouseholdMemberDeparture::count())->toBe(0);

    Queue::assertPushed(SendExpoPushNotificationsJob::class, fn (SendExpoPushNotificationsJob $job) => $job->tokens === [$this->other_admin->pushTokens()->value('token')]
        && $job->data['route'] === '/');
});

it('lists the pending departures only for the admins', function () {
    $task = departureTask($this->household, $this->member, ['name' => 'Dishes']);
    departureTask($this->household, $this->admin);

    Sanctum::actingAs($this->member);
    $this->deleteJson("{$this->url}/leave")->assertOk();
    $this->getJson("{$this->url}/member-departures")->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->getJson("{$this->url}/member-departures")
        ->assertOk()
        ->assertJsonCount(1, 'member_departures')
        ->assertJsonPath('member_departures.0.user.id', $this->member->id)
        ->assertJsonPath('member_departures.0.tasks.0.id', $task->id)
        ->assertJsonCount(1, 'member_departures.0.tasks');
});

it('deletes the chosen tasks of the former member and closes the departure', function () {
    $deleted = departureTask($this->household, $this->member);
    $kept = departureTask($this->household, $this->member);
    $admin_task = departureTask($this->household, $this->admin);

    Sanctum::actingAs($this->member);
    $this->deleteJson("{$this->url}/leave")->assertOk();
    $departure = HouseholdMemberDeparture::sole();

    Sanctum::actingAs($this->admin);
    $this->postJson("{$this->url}/member-departures/{$departure->id}/resolve", ['task_ids' => [$admin_task->id]])->assertUnprocessable();
    $this->postJson("{$this->url}/member-departures/{$departure->id}/resolve", ['task_ids' => [$deleted->id]])
        ->assertOk()
        ->assertJsonPath('deleted_count', 1);

    expect(Task::pluck('id')->sort()->values()->all())->toBe([$kept->id, $admin_task->id])
        ->and($departure->fresh())->resolved_by->toBe($this->admin->id)->resolved_at->not->toBeNull();

    Sanctum::actingAs($this->other_admin);
    $this->postJson("{$this->url}/member-departures/{$departure->id}/resolve", ['task_ids' => []])
        ->assertForbidden()
        ->assertJsonPath('message', __('app.member_departure_resolved'));
    $this->getJson("{$this->url}/member-departures")->assertOk()->assertJsonCount(0, 'member_departures');
});

it('keeps every task when the admin chooses none', function () {
    $task = departureTask($this->household, $this->member);

    Sanctum::actingAs($this->member);
    $this->deleteJson("{$this->url}/leave")->assertOk();

    Sanctum::actingAs($this->admin);
    $this->postJson("{$this->url}/member-departures/".HouseholdMemberDeparture::sole()->id.'/resolve', ['task_ids' => []])
        ->assertOk()
        ->assertJsonPath('deleted_count', 0);

    expect($task->fresh()->trashed())->toBeFalse();
});

it('drops the pending departure when the member returns', function () {
    departureTask($this->household, $this->member);

    Sanctum::actingAs($this->member);
    $this->deleteJson("{$this->url}/leave")->assertOk();
    $this->household->users()->attach($this->member->id, ['role' => RoleEnum::USER]);

    expect(HouseholdMemberDeparture::sole()->resolved_at)->not->toBeNull();
});

it('does not treat deleting the household as members leaving', function () {
    departureTask($this->household, $this->member);
    $reward = $this->household->rewards()->create(['user_id' => $this->member->id, 'name' => 'Pizza', 'points_cost' => 50]);

    Sanctum::actingAs($this->admin);
    $this->deleteJson($this->url)->assertOk();

    expect(HouseholdUser::count())->toBe(0)
        ->and(HouseholdMemberDeparture::count())->toBe(0)
        ->and($reward->fresh()->trashed())->toBeFalse();
    Queue::assertNothingPushed();
});

it('does not let the creator of the household leave, be removed or lose the admin role', function () {
    $owner_membership = departureMembership($this->household, $this->admin);

    Sanctum::actingAs($this->admin);
    $this->deleteJson("{$this->url}/leave")->assertForbidden()->assertJsonPath('message', __('app.household_owner_cannot_leave'));
    $this->deleteJson("/api/household-users/{$owner_membership->id}")->assertForbidden();

    Sanctum::actingAs($this->other_admin);
    $this->deleteJson("/api/household-users/{$owner_membership->id}")
        ->assertForbidden()
        ->assertJsonPath('message', __('app.household_owner_membership_locked'));
    $this->putJson("/api/household-users/{$owner_membership->id}", ['role' => RoleEnum::USER->value])->assertForbidden();

    expect($owner_membership->fresh()->role)->toBe(RoleEnum::ADMIN);
});

it('closes the departures nobody decided about for a long time and keeps the tasks', function () {
    $task = departureTask($this->household, $this->member);

    Sanctum::actingAs($this->member);
    $this->deleteJson("{$this->url}/leave")->assertOk();

    $this->travel(HouseholdMemberDeparture::AUTO_CLOSE_DAYS - 1)->days();
    expect(CloseStaleMemberDeparturesAction::run())->toBe(0);

    $this->travel(1)->days();
    expect(CloseStaleMemberDeparturesAction::run())->toBe(1)
        ->and(HouseholdMemberDeparture::sole())->resolved_at->not->toBeNull()->resolved_by->toBeNull()
        ->and($task->fresh()->trashed())->toBeFalse();
});

it('refunds the pending redemptions of the rewards of a member who leaves', function () {
    $reward = $this->household->rewards()->create(['user_id' => $this->member->id, 'name' => 'Pizza', 'points_cost' => 50]);
    $pending = RewardRedemption::create(['household_id' => $this->household->id, 'reward_id' => $reward->id, 'user_id' => $this->admin->id, 'points_spent' => 50]);
    $fulfilled = RewardRedemption::create(['household_id' => $this->household->id, 'reward_id' => $reward->id, 'user_id' => $this->other_admin->id, 'points_spent' => 50, 'fulfilled_at' => now()]);
    HouseholdUser::where('user_id', $this->admin->id)->update(['points_balance' => 10]);

    Sanctum::actingAs($this->member);
    $this->deleteJson("{$this->url}/leave")->assertOk();

    expect(departureMembership($this->household, $this->admin)->points_balance)->toBe(60)
        ->and(departureMembership($this->household, $this->other_admin)->points_balance)->toBe(0)
        ->and($pending->fresh()->refunded_at)->not->toBeNull()
        ->and($fulfilled->fresh()->refunded_at)->toBeNull()
        ->and(PointTransaction::where('type', PointTransactionType::REWARD_REFUND)->sole())
        ->user_id->toBe($this->admin->id)->amount->toBe(50)->balance_after->toBe(60)->reward_redemption_id->toBe($pending->id);

    Queue::assertPushed(SendExpoPushNotificationsJob::class, fn (SendExpoPushNotificationsJob $job) => $job->tokens === [$this->admin->pushTokens()->value('token')]
        && $job->data['route'] === '/rewards');
});

it('lists the pending redemptions and lets the creator or the redeemer mark them fulfilled', function () {
    $reward = $this->household->rewards()->create(['user_id' => $this->member->id, 'name' => 'Pizza', 'points_cost' => 50]);
    $redemption = RewardRedemption::create(['household_id' => $this->household->id, 'reward_id' => $reward->id, 'user_id' => $this->admin->id, 'points_spent' => 50]);

    Sanctum::actingAs($this->member);
    $this->getJson("{$this->url}/reward-redemptions")
        ->assertOk()
        ->assertJsonPath('to_fulfill.0.id', $redemption->id)
        ->assertJsonCount(0, 'waiting');

    Sanctum::actingAs($this->admin);
    $this->getJson("{$this->url}/reward-redemptions")
        ->assertOk()
        ->assertJsonCount(0, 'to_fulfill')
        ->assertJsonPath('waiting.0.id', $redemption->id);

    Sanctum::actingAs($this->other_admin);
    $this->postJson("{$this->url}/reward-redemptions/{$redemption->id}/fulfill")->assertForbidden();

    Sanctum::actingAs($this->member);
    $this->postJson("{$this->url}/reward-redemptions/{$redemption->id}/fulfill")->assertOk();
    $this->postJson("{$this->url}/reward-redemptions/{$redemption->id}/fulfill")
        ->assertForbidden()
        ->assertJsonPath('message', __('app.reward_redemption_not_pending'));

    expect($redemption->fresh()->fulfilled_at)->not->toBeNull();
});
