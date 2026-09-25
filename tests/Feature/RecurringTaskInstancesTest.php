<?php

use App\Actions\RecurringTask\GenerateRecurringTaskInstancesAction;
use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function recurringUser(): User
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

function recurringTask(Household $household, array $attributes = []): Task
{
    return Task::create([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => 'Task '.uniqid(),
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'day',
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->user = recurringUser();
    $this->other = recurringUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000002', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::ADMIN]);
    $this->household->users()->attach($this->other->id, ['role' => RoleEnum::USER]);
});

it('does not create an instance before the period has elapsed', function () {
    recurringTask($this->household);

    expect(GenerateRecurringTaskInstancesAction::make()->handle())->toBe(0);
});

it('creates the next instance after the period and expires the open one', function () {
    $task = recurringTask($this->household);

    $this->travel(25)->hours();

    expect(GenerateRecurringTaskInstancesAction::make()->handle())->toBe(1);
    expect($task->taskInstances()->count())->toBe(2);
    expect($task->taskInstances()->oldest('id')->first()->status)->toBe(TaskInstanceStatusEnum::EXPIRED);
    expect($task->taskInstances()->latest('id')->first()->due_at)->not->toBeNull();
});

it('ignores non-recurring tasks', function () {
    recurringTask($this->household, ['is_recurring' => false, 'recurrence_interval' => null, 'recurrence_unit' => null]);

    $this->travel(48)->hours();

    expect(GenerateRecurringTaskInstancesAction::make()->handle())->toBe(0);
});

it('assigns the fixed member to every generated instance', function () {
    $task = recurringTask($this->household, ['assignment_mode' => 'fixed', 'fixed_user_id' => $this->other->id]);

    $this->travel(25)->hours();
    GenerateRecurringTaskInstancesAction::make()->handle();

    $instance = $task->taskInstances()->latest('id')->first();
    expect($instance->taskInstanceUsers->pluck('user_id')->all())->toBe([$this->other->id]);
});

it('rotates the assignee between the members', function () {
    $task = recurringTask($this->household, ['assignment_mode' => 'rotating']);
    $task->rotations()->createMany([
        ['user_id' => $this->user->id, 'rotation_order' => 0],
        ['user_id' => $this->other->id, 'rotation_order' => 1],
    ]);

    $assignees = [];
    foreach (range(1, 3) as $ignored) {
        $this->travel(25)->hours();
        GenerateRecurringTaskInstancesAction::make()->handle();
        $assignees[] = $task->taskInstances()->latest('id')->first()->taskInstanceUsers()->value('user_id');
    }

    expect($assignees)->toBe([$this->user->id, $this->other->id, $this->user->id]);
});

it('leaves the generated instance unassigned without an assignment', function () {
    $task = recurringTask($this->household);

    $this->travel(25)->hours();
    GenerateRecurringTaskInstancesAction::make()->handle();

    expect($task->taskInstances()->latest('id')->first()->taskInstanceUsers()->count())->toBe(0);
});

it('stores a rotating task over the api and assigns the first instance', function () {
    Sanctum::actingAs($this->user);

    $this->postJson("/api/households/{$this->household->id}/tasks", [
        'name' => 'Vacuum',
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
        'assignment_mode' => 'rotating',
        'rotation_user_ids' => [$this->other->id, $this->user->id],
    ])->assertOk();

    $task = Task::where('name', 'Vacuum')->firstOrFail();
    expect($task->rotations()->pluck('user_id')->all())->toBe([$this->other->id, $this->user->id]);
    expect($task->taskInstances()->first()->taskInstanceUsers()->value('user_id'))->toBe($this->other->id);
});

it('rejects an assignee outside the household and assignment on non-recurring tasks', function () {
    Sanctum::actingAs($this->user);
    $stranger = recurringUser();
    $payload = ['name' => 'Mop', 'duration_minutes' => 10, 'difficulty' => 'easy'];

    $this->postJson("/api/households/{$this->household->id}/tasks", [
        ...$payload, 'is_recurring' => true, 'recurrence_interval' => 1, 'recurrence_unit' => 'day',
        'assignment_mode' => 'fixed', 'fixed_user_id' => $stranger->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('assignment_mode');

    $this->postJson("/api/households/{$this->household->id}/tasks", [
        ...$payload, 'assignment_mode' => 'fixed', 'fixed_user_id' => $this->other->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('assignment_mode');
});

it('clears the assignment when a task is no longer recurring', function () {
    Sanctum::actingAs($this->user);
    $task = recurringTask($this->household, ['assignment_mode' => 'fixed', 'fixed_user_id' => $this->other->id]);

    $this->putJson("/api/households/{$this->household->id}/tasks/{$task->id}", ['is_recurring' => false])->assertOk();

    expect($task->fresh()->assignment_mode->value)->toBe('none')->and($task->fresh()->fixed_user_id)->toBeNull();
});

it('lists the household members for any member', function () {
    Sanctum::actingAs($this->other);

    $this->getJson("/api/households/{$this->household->id}/members")
        ->assertOk()
        ->assertJsonCount(2, 'members')
        ->assertJsonPath('members.0.user_id', $this->user->id);
});
