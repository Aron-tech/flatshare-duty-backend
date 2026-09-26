<?php

use App\Actions\RecurringTask\ReleaseOverdueTaskClaimsAction;
use App\Enums\PointTransactionType;
use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskUserWeight;
use App\Models\User;
use App\Models\WeeklyPointGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function completionUser(): User
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

function completionInstance(Household $household, int $max_user = 1): TaskInstance
{
    $task = Task::create([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => 'Task '.uniqid(),
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'max_user' => $max_user,
    ]);

    return TaskInstance::create([
        'task_id' => $task->id,
        'household_id' => $household->id,
        'status' => TaskInstanceStatusEnum::PENDING,
    ]);
}

function completeUrl(TaskInstance $task_instance): string
{
    return "/api/households/{$task_instance->household_id}/task-instances/{$task_instance->id}/complete";
}

beforeEach(function () {
    $this->user = completionUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::USER, 'points_balance' => 5]);
    Sanctum::actingAs($this->user);
});

it('completes a claimed task instance and credits the points', function () {
    $instance = completionInstance($this->household);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);
    TaskUserWeight::create(['household_id' => $this->household->id, 'user_id' => $this->user->id, 'task_id' => $instance->task_id, 'weight' => 'hate']);

    $this->postJson(completeUrl($instance))
        ->assertOk()
        ->assertJsonPath('points', 13)
        ->assertJsonPath('points_balance', 18)
        ->assertJsonPath('weekly_points', 13);

    $instance->refresh();
    expect($instance->status)->toBe(TaskInstanceStatusEnum::ACCEPTED)
        ->and($instance->completed_at)->not->toBeNull()
        ->and($this->household->householdUsers()->first()->points_balance)->toBe(18);

    $transaction = PointTransaction::sole();
    expect($transaction->type)->toBe(PointTransactionType::TASK_COMPLETION)
        ->and($transaction->amount)->toBe(13)
        ->and($transaction->balance_after)->toBe(18)
        ->and($transaction->task_instance_id)->toBe($instance->id);
});

it('uses the neutral weight when nobody weighted the task', function () {
    $instance = completionInstance($this->household);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);

    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 10);
});

it('shares the points by the common weight of the members between the claimers', function () {
    $other = completionUser();
    $this->household->users()->attach($other->id, ['role' => RoleEnum::USER]);
    $instance = completionInstance($this->household, 2);
    $instance->task->update(['base_points' => 100]);
    TaskUserWeight::create(['household_id' => $this->household->id, 'user_id' => $other->id, 'task_id' => $instance->task_id, 'weight' => 'hate']);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);
    $instance->taskInstanceUsers()->create(['user_id' => $other->id]);

    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 56);
    Sanctum::actingAs($other);
    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 56);
});

it('does not let anyone join once a claimer completed their part', function () {
    $other = completionUser();
    $third = completionUser();
    $this->household->users()->attach([$other->id, $third->id], ['role' => RoleEnum::USER]);
    $instance = completionInstance($this->household, 3);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);
    $instance->taskInstanceUsers()->create(['user_id' => $other->id]);

    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 5);

    Sanctum::actingAs($third);
    $this->postJson("/api/households/{$this->household->id}/task-instances/{$instance->id}/claim")->assertForbidden();
});

it('lets a member take over the released part of an overdue task for the same share', function () {
    $other = completionUser();
    $rescuer = completionUser();
    $this->household->users()->attach([$other->id, $rescuer->id], ['role' => RoleEnum::USER]);
    $instance = completionInstance($this->household, 2);
    $instance->update(['due_at' => now()->addDay()]);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);
    $instance->taskInstanceUsers()->create(['user_id' => $other->id]);
    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 5);

    $this->travel(2)->days();
    ReleaseOverdueTaskClaimsAction::run();

    Sanctum::actingAs($rescuer);
    $this->postJson("/api/households/{$this->household->id}/task-instances/{$instance->id}/claim")->assertOk();
    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 5);
    expect($instance->refresh()->status)->toBe(TaskInstanceStatusEnum::ACCEPTED);
});

it('does not let anyone join a penalty task', function () {
    $other = completionUser();
    $this->household->users()->attach($other->id, ['role' => RoleEnum::USER]);
    $goal = WeeklyPointGoal::create(['household_id' => $this->household->id, 'user_id' => $this->user->id, 'week_starts_at' => WeeklyPointGoal::weekDate(now()), 'target_points' => 100]);
    $instance = completionInstance($this->household, 2);
    $instance->claimFor($this->user->id, ['weekly_point_goal_id' => $goal->id, 'penalty_points' => 5]);

    Sanctum::actingAs($other);
    $this->postJson("/api/households/{$this->household->id}/task-instances/{$instance->id}/claim")->assertForbidden();
});

it('returns the spendable points after the completion', function () {
    $instance = completionInstance($this->household);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);

    $spendable_points = $this->postJson(completeUrl($instance))->assertOk()->json('spendable_points');

    expect($spendable_points)->toBeInt()
        ->toBe($this->getJson("/api/households/{$this->household->id}/me")->json('spendable_points'));
});

it('gives no solo bonus when a multi-user task has only one claimer', function () {
    $instance = completionInstance($this->household, 2);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);

    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 10);
    expect($instance->refresh()->status)->toBe(TaskInstanceStatusEnum::ACCEPTED);
});

it('pays the overdue bounty only to a member who claimed the task after its due date', function () {
    $late = completionInstance($this->household);
    $late->update(['due_at' => now()->addDay()]);
    $late->taskInstanceUsers()->create(['user_id' => $this->user->id]);

    $rescued = completionInstance($this->household);
    $rescued->update(['due_at' => now()->addDay()]);

    $this->travel(4)->days();
    $rescued->taskInstanceUsers()->create(['user_id' => $this->user->id]);

    $this->postJson(completeUrl($late))->assertOk()->assertJsonPath('points', 10);
    $this->postJson(completeUrl($rescued))->assertOk()->assertJsonPath('points', 12);
});

it('fixes the overdue bounty when the task is claimed, so holding it does not raise the bounty', function () {
    $instance = completionInstance($this->household);
    $instance->task->update(['base_points' => 100]);
    $instance->update(['due_at' => now()]);

    $this->travel(1)->day();
    $this->travel(1)->minute();
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);
    $this->travel(5)->days();

    $this->getJson("/api/households/{$this->household->id}/task-instances")->assertOk()->assertJsonPath('claimed.0.points', 105);
    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 105);
});

it('keeps the task instance open until every claimer completes it', function () {
    $other = completionUser();
    $this->household->users()->attach($other->id, ['role' => RoleEnum::USER]);

    $instance = completionInstance($this->household, 2);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);
    $instance->taskInstanceUsers()->create(['user_id' => $other->id]);

    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 5);
    expect($instance->refresh()->status)->toBe(TaskInstanceStatusEnum::PENDING);

    Sanctum::actingAs($other);
    $this->postJson(completeUrl($instance))->assertOk();
    expect($instance->refresh()->status)->toBe(TaskInstanceStatusEnum::ACCEPTED);
});

it('does not complete a task instance twice or without a claim', function () {
    $instance = completionInstance($this->household);

    $this->postJson(completeUrl($instance))->assertForbidden();

    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);
    $this->postJson(completeUrl($instance))->assertOk();
    $this->postJson(completeUrl($instance))->assertForbidden();

    expect(PointTransaction::count())->toBe(1);
});

it('does not let a non member complete a task instance', function () {
    $instance = completionInstance($this->household);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);
    Sanctum::actingAs(completionUser());

    $this->postJson(completeUrl($instance))->assertForbidden();
});
