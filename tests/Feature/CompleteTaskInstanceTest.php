<?php

use App\Enums\PointTransactionType;
use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskUserWeight;
use App\Models\User;
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
        ->assertJsonPath('points', 12)
        ->assertJsonPath('points_balance', 17);

    $instance->refresh();
    expect($instance->status)->toBe(TaskInstanceStatusEnum::ACCEPTED)
        ->and($instance->completed_at)->not->toBeNull()
        ->and($this->household->householdUsers()->first()->points_balance)->toBe(17);

    $transaction = PointTransaction::sole();
    expect($transaction->type)->toBe(PointTransactionType::TASK_COMPLETION)
        ->and($transaction->amount)->toBe(12)
        ->and($transaction->balance_after)->toBe(17)
        ->and($transaction->task_instance_id)->toBe($instance->id);
});

it('uses the neutral weight when the user has no weight for the task', function () {
    $instance = completionInstance($this->household);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);

    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 10);
});

it('gives the solo bonus when a multi-user task has only one claimer', function () {
    $instance = completionInstance($this->household, 2);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);

    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 15);
    expect($instance->refresh()->status)->toBe(TaskInstanceStatusEnum::ACCEPTED);
});

it('keeps the task instance open until every claimer completes it', function () {
    $other = completionUser();
    $this->household->users()->attach($other->id, ['role' => RoleEnum::USER]);

    $instance = completionInstance($this->household, 2);
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);
    $instance->taskInstanceUsers()->create(['user_id' => $other->id]);

    $this->postJson(completeUrl($instance))->assertOk()->assertJsonPath('points', 10);
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
