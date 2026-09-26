<?php

use App\Actions\RecurringTask\ReleaseOverdueTaskClaimsAction;
use App\Actions\TaskOffer\ExpireTaskOffersAction;
use App\Actions\WeeklyPointGoal\CloseWeeklyPointGoalsAction;
use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Enums\PointTransactionType;
use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Enums\TaskOfferStatusEnum;
use App\Jobs\SendExpoPushNotificationsJob;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\PointTransaction;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskInstanceUser;
use App\Models\TaskOffer;
use App\Models\User;
use App\Models\WeeklyPointGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function offerUser(): User
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
    $user->pushTokens()->create(['token' => "ExpoPushToken[{$user->id}]", 'platform' => 'ios']);

    return $user;
}

/**
 * A 10 point weekly task's open instance claimed by the user, due in two days.
 */
function offerClaim(Household $household, User $user, array $task_attributes = []): TaskInstance
{
    $task = Task::create([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => 'Task '.uniqid(),
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
        ...$task_attributes,
    ]);
    $task_instance = $task->taskInstances()->sole();
    $task_instance->update(['due_at' => now()->addDays(2)]);
    $task_instance->claimFor($user->id);

    return $task_instance;
}

function balanceOf(Household $household, User $user): int
{
    return HouseholdUser::where('household_id', $household->id)->where('user_id', $user->id)->value('points_balance');
}

function setBalance(Household $household, User $user, int $points): void
{
    HouseholdUser::where('household_id', $household->id)->where('user_id', $user->id)->update(['points_balance' => $points]);
}

beforeEach(function () {
    Queue::fake();
    $this->travelTo(WeeklyPointGoal::weekStartsAt());
    $this->user = offerUser();
    $this->member = offerUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::ADMIN]);
    $this->household->users()->attach($this->member->id, ['role' => RoleEnum::USER]);
    $this->url = "/api/households/{$this->household->id}";
    Sanctum::actingAs($this->user);
});

it('hands a task over for escrowed points that are paid out when the taker completes it', function () {
    setBalance($this->household, $this->user, 30);
    $task_instance = offerClaim($this->household, $this->user);

    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/offers", ['points' => 20])
        ->assertOk()
        ->assertJsonPath('spendable_points', 10);
    $task_offer = TaskOffer::sole();
    expect(balanceOf($this->household, $this->user))->toBe(10)
        ->and(PointTransaction::where('type', PointTransactionType::DELEGATION_ESCROW_LOCK)->sole()->amount)->toBe(20);
    Queue::assertPushed(SendExpoPushNotificationsJob::class);

    $this->getJson("{$this->url}/task-instances")
        ->assertJsonPath('claimed.0.my_offer.points', 20)
        ->assertJsonPath('claimed.0.min_offer_points', 0);

    Sanctum::actingAs($this->member);
    $this->getJson("{$this->url}/task-instances")
        ->assertJsonPath('offered.0.id', $task_instance->id)
        ->assertJsonPath('offered.0.offer.points', 20)
        ->assertJsonPath('offered.0.points', 10);

    $this->postJson("{$this->url}/task-offers/{$task_offer->id}/accept")->assertOk();
    expect($task_offer->refresh()->status)->toBe(TaskOfferStatusEnum::ACCEPTED)
        ->and($task_instance->taskInstanceUsers()->sole()->user_id)->toBe($this->member->id);

    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/complete")
        ->assertOk()
        ->assertJsonPath('points', 10)
        ->assertJsonPath('offer_points', 20)
        ->assertJsonPath('points_balance', 30);

    expect($task_offer->refresh()->status)->toBe(TaskOfferStatusEnum::COMPLETED)
        ->and(balanceOf($this->household, $this->user))->toBe(10);
});

it('does not let the offerer take their own offer or offer more than their spendable points', function () {
    setBalance($this->household, $this->user, 5);
    $task_instance = offerClaim($this->household, $this->user);

    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/offers", ['points' => 6])->assertForbidden();
    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/offers", ['points' => 5])->assertOk();
    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/offers", ['points' => 0])->assertForbidden();

    $this->postJson("{$this->url}/task-offers/".TaskOffer::sole()->id.'/accept')->assertForbidden();
});

it('only lets the addressed member take over a targeted offer', function () {
    $other = offerUser();
    $this->household->users()->attach($other->id, ['role' => RoleEnum::USER]);
    $task_instance = offerClaim($this->household, $this->user);

    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/offers", ['points' => 0, 'target_user_id' => $this->member->id])->assertOk();
    $task_offer = TaskOffer::sole();

    Sanctum::actingAs($other);
    $this->getJson("{$this->url}/task-instances")->assertJsonCount(0, 'offered');
    $this->postJson("{$this->url}/task-offers/{$task_offer->id}/accept")->assertForbidden();

    Sanctum::actingAs($this->member);
    $this->postJson("{$this->url}/task-offers/{$task_offer->id}/accept")->assertOk();
});

it('refunds a withdrawn offer, but an accepted one cannot be withdrawn', function () {
    setBalance($this->household, $this->user, 20);
    $task_instance = offerClaim($this->household, $this->user);

    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/offers", ['points' => 20])->assertOk();
    $this->deleteJson("{$this->url}/task-offers/".TaskOffer::sole()->id)->assertOk()->assertJsonPath('spendable_points', 20);
    expect(TaskOffer::sole()->status)->toBe(TaskOfferStatusEnum::CANCELLED);

    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/offers", ['points' => 5])->assertOk();
    $task_offer = TaskOffer::latest('id')->first();
    Sanctum::actingAs($this->member);
    $this->postJson("{$this->url}/task-offers/{$task_offer->id}/accept")->assertOk();

    Sanctum::actingAs($this->user);
    $this->deleteJson("{$this->url}/task-offers/{$task_offer->id}")->assertForbidden();
    expect(balanceOf($this->household, $this->user))->toBe(15);
});

it('refunds the offer nobody took over by the due date', function () {
    setBalance($this->household, $this->user, 20);
    $task_instance = offerClaim($this->household, $this->user);
    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/offers", ['points' => 20])->assertOk();

    $this->travel(3)->days();
    expect(ExpireTaskOffersAction::run())->toBe(1);

    expect(TaskOffer::sole()->status)->toBe(TaskOfferStatusEnum::EXPIRED)
        ->and(balanceOf($this->household, $this->user))->toBe(20);
});

it('refunds the offer when the offerer completes the task themselves', function () {
    setBalance($this->household, $this->user, 20);
    $task_instance = offerClaim($this->household, $this->user);
    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/offers", ['points' => 20])->assertOk();

    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/complete")->assertOk();

    expect(TaskOffer::sole()->status)->toBe(TaskOfferStatusEnum::CANCELLED)
        ->and(balanceOf($this->household, $this->user))->toBe(30);
});

it('turns a penalty into a point deduction when it is handed over', function () {
    Task::create([
        'household_id' => $this->household->id,
        'created_by' => $this->user->id,
        'name' => 'Dishes',
        'duration_minutes' => 100,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
    ]);
    earnPointsFor($this->household, $this->member, 50);
    $this->travel(1)->week();
    CloseWeeklyPointGoalsAction::run();
    $penalty = TaskInstanceUser::whereNotNull('weekly_point_goal_id')->sole();
    // 50 pont a cél tagonként, a 90%-os vonal 45 pont.
    expect($penalty->penalty_points)->toBe(45);
    setBalance($this->household, $this->user, 60);

    $this->getJson("{$this->url}/task-instances")
        ->assertJsonPath('claimed.0.is_penalty', true)
        ->assertJsonPath('claimed.0.min_offer_points', 45);
    $this->postJson("{$this->url}/task-instances/{$penalty->task_instance_id}/offers", ['points' => 44])->assertForbidden();
    $this->postJson("{$this->url}/task-instances/{$penalty->task_instance_id}/offers", ['points' => 45])->assertOk();

    Sanctum::actingAs($this->member);
    $task_offer = TaskOffer::sole();
    $this->getJson("{$this->url}/task-instances")->assertJsonPath('offered.0.offer.is_penalty', true);
    $this->postJson("{$this->url}/task-offers/{$task_offer->id}/accept")->assertOk();

    $this->getJson("{$this->url}/task-instances")
        ->assertJsonPath('claimed.0.is_penalty', false)
        ->assertJsonPath('claimed.0.points', 100)
        ->assertJsonPath('claimed.0.offer_points', 45);
    $stats = $this->getJson("{$this->url}/stats")->json('penalties');
    expect(collect($stats)->where('status', 'pending')->pluck('user_id')->all())->not->toContain($this->member->id);

    $this->postJson("{$this->url}/task-instances/{$penalty->task_instance_id}/complete")
        ->assertOk()
        ->assertJsonPath('points', 100)
        ->assertJsonPath('offer_points', 45);
    expect(PointTransaction::where('user_id', $this->member->id)->where('task_instance_id', $penalty->task_instance_id)->where('type', PointTransactionType::TASK_COMPLETION)->sole()->amount)->toBe(100)
        ->and(balanceOf($this->household, $this->user))->toBe(15);
});

it('burns the penalty part of the offer when the taker misses the task', function () {
    Task::create([
        'household_id' => $this->household->id,
        'created_by' => $this->user->id,
        'name' => 'Dishes',
        'duration_minutes' => 100,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
    ]);
    earnPointsFor($this->household, $this->member, 50);
    $this->travel(1)->week();
    CloseWeeklyPointGoalsAction::run();
    $penalty = TaskInstanceUser::whereNotNull('weekly_point_goal_id')->sole();
    setBalance($this->household, $this->user, 60);
    $this->postJson("{$this->url}/task-instances/{$penalty->task_instance_id}/offers", ['points' => 50])->assertOk();
    Sanctum::actingAs($this->member);
    $this->postJson("{$this->url}/task-offers/".TaskOffer::sole()->id.'/accept')->assertOk();

    $this->travel(8)->days();
    ReleaseOverdueTaskClaimsAction::run();

    expect(TaskOffer::sole()->status)->toBe(TaskOfferStatusEnum::FAILED)
        ->and(PointTransaction::where('type', PointTransactionType::DELEGATION_PENALTY_BURN)->sole()->amount)->toBe(45)
        ->and(PointTransaction::where('type', PointTransactionType::DELEGATION_ESCROW_REFUND)->sole()->amount)->toBe(5)
        ->and(balanceOf($this->household, $this->user))->toBe(15)
        ->and(PointTransaction::where('type', PointTransactionType::MISSED_TASK_PENALTY)->where('user_id', $this->member->id)->sole()->amount)->toBe(0);
});

it('deducts the covered shortfall when a penalty task is missed and puts the task back to the pool', function () {
    Task::create([
        'household_id' => $this->household->id,
        'created_by' => $this->user->id,
        'name' => 'Dishes',
        'duration_minutes' => 100,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
    ]);
    $this->travel(1)->week();
    CloseWeeklyPointGoalsAction::run();
    $penalty = TaskInstanceUser::whereNotNull('weekly_point_goal_id')->where('user_id', $this->user->id)->sole();
    setBalance($this->household, $this->user, 30);
    Queue::fake();

    $this->travel(8)->days();
    ReleaseOverdueTaskClaimsAction::run();

    expect($penalty->refresh()->trashed())->toBeTrue()
        ->and(PointTransaction::where('type', PointTransactionType::MISSED_TASK_PENALTY)->where('user_id', $this->user->id)->sole()->amount)->toBe(30)
        ->and(balanceOf($this->household, $this->user))->toBe(0);
    Queue::assertPushed(SendExpoPushNotificationsJob::class);

    Sanctum::actingAs($this->member);
    $this->getJson("{$this->url}/task-instances")->assertJsonFragment(['id' => $penalty->task_instance_id]);
});

it('grants one grace day per period that moves the due date and delays the release', function () {
    $task_instance = offerClaim($this->household, $this->user);
    $second = offerClaim($this->household, $this->user);
    $due_at = $task_instance->due_at;

    $this->getJson("{$this->url}/me")->assertJsonPath('grace_days_left', 1);
    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/grace-day")
        ->assertOk()
        ->assertJsonPath('grace_days_left', 0);
    expect($task_instance->refresh()->due_at->equalTo($due_at->addDay()))->toBeTrue();

    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/grace-day")->assertForbidden();
    $this->postJson("{$this->url}/task-instances/{$second->id}/grace-day")->assertForbidden();

    $this->travelTo(now()->addDays(2)->addHour());
    expect(ReleaseOverdueTaskClaimsAction::run())->toBe(1);
    expect($task_instance->taskInstanceUsers()->count())->toBe(1);

    $this->travel(1)->week();
    $this->getJson("{$this->url}/me")->assertJsonPath('grace_days_left', 1);
});

it('does not grant a grace day on an overdue task or one without a due date', function () {
    $task_instance = offerClaim($this->household, $this->user);
    $task_instance->update(['due_at' => now()->subHour()]);
    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/grace-day")->assertForbidden();

    $task_instance->update(['due_at' => null]);
    $this->postJson("{$this->url}/task-instances/{$task_instance->id}/grace-day")->assertForbidden();
});

it('releases the open claims of a member who leaves the household', function () {
    $task_instance = offerClaim($this->household, $this->member, ['is_recurring' => false, 'recurrence_interval' => null, 'recurrence_unit' => null]);
    setBalance($this->household, $this->user, 10);
    $offered = offerClaim($this->household, $this->user);
    $this->postJson("{$this->url}/task-instances/{$offered->id}/offers", ['points' => 10])->assertOk();

    Sanctum::actingAs($this->member);
    $this->postJson("{$this->url}/task-offers/".TaskOffer::sole()->id.'/accept')->assertOk();
    $this->deleteJson("{$this->url}/leave")->assertOk();

    expect($task_instance->taskInstanceUsers()->exists())->toBeFalse()
        ->and($offered->taskInstanceUsers()->exists())->toBeFalse()
        ->and(TaskOffer::sole()->status)->toBe(TaskOfferStatusEnum::FAILED)
        ->and(balanceOf($this->household, $this->user))->toBe(10);
});

it('does not raise the goals of the household with a penalty instance of an instant task', function () {
    Task::create([
        'household_id' => $this->household->id,
        'created_by' => $this->user->id,
        'name' => 'Windows',
        'duration_minutes' => 100,
        'difficulty' => 'easy',
    ])->taskInstances()->update(['status' => TaskInstanceStatusEnum::ACCEPTED, 'completed_at' => now()]);
    earnPointsFor($this->household, $this->member, 100);

    $this->travel(1)->week();
    CloseWeeklyPointGoalsAction::run();
    expect(TaskInstanceUser::whereNotNull('weekly_point_goal_id')->where('user_id', $this->user->id)->exists())->toBeTrue();

    $goals = RecalculateWeeklyPointGoalsAction::run($this->household);
    expect($goals->pluck('target_points')->all())->toBe([0, 0]);
});

it('does not list a member who took over an overdue task for the bounty as penalized', function () {
    $task_instance = offerClaim($this->household, $this->user);
    $task_instance->taskInstanceUsers()->forceDelete();
    $task_instance->update(['due_at' => now()->subDay()]);
    $task_instance->claimFor($this->member->id);

    $this->getJson("{$this->url}/stats")->assertJsonCount(0, 'penalties');
});

function earnPointsFor(Household $household, User $user, int $amount): void
{
    PointTransaction::create([
        'household_id' => $household->id,
        'user_id' => $user->id,
        'amount' => $amount,
        'balance_after' => $amount,
        'type' => PointTransactionType::TASK_COMPLETION,
    ]);
}
