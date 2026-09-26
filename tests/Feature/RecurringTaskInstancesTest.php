<?php

use App\Actions\RecurringTask\GenerateRecurringTaskInstancesAction;
use App\Actions\RecurringTask\ReleaseOverdueTaskClaimsAction;
use App\Enums\PointTransactionType;
use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\Task;
use App\Models\TaskInstanceUser;
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

function completeLatestInstance(Task $task): void
{
    $task->taskInstances()->latest('id')->first()->update(['status' => TaskInstanceStatusEnum::ACCEPTED, 'completed_at' => now()]);
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

it('gives the first instance of a recurring task a due date', function () {
    $this->freezeSecond();
    $task = recurringTask($this->household);

    expect($task->taskInstances()->sole()->due_at->equalTo(now()->addDay()))->toBeTrue();
});

it('creates the next instance after the period when the previous one is done', function () {
    $task = recurringTask($this->household);
    completeLatestInstance($task);

    $this->travel(25)->hours();

    expect(GenerateRecurringTaskInstancesAction::make()->handle())->toBe(1);
    expect($task->taskInstances()->count())->toBe(2);
    expect($task->taskInstances()->latest('id')->first()->due_at)->not->toBeNull();
});

it('carries over an overdue open instance instead of creating a new one', function () {
    $task = recurringTask($this->household);

    $this->travel(3)->days();

    expect(GenerateRecurringTaskInstancesAction::make()->handle())->toBe(0);
    expect($task->taskInstances()->sole()->status)->toBe(TaskInstanceStatusEnum::PENDING);
});

it('releases the claims not completed by the due date and records them as missed', function () {
    $task = recurringTask($this->household);
    $instance = $task->taskInstances()->sole();
    $missed = $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);

    $this->travel(2)->days();
    $rescue = $instance->taskInstanceUsers()->create(['user_id' => $this->other->id]);

    expect(ReleaseOverdueTaskClaimsAction::make()->handle())->toBe(1)
        ->and(TaskInstanceUser::withTrashed()->find($missed->id)->trashed())->toBeTrue()
        ->and($rescue->fresh()->trashed())->toBeFalse()
        ->and(PointTransaction::sole()->type)->toBe(PointTransactionType::MISSED_TASK_PENALTY)
        ->and(PointTransaction::sole()->amount)->toBe(0);
});

it('lets anyone claim a released task and shows the bounty only to the ones who did not miss it', function () {
    $task = recurringTask($this->household);
    $instance = $task->taskInstances()->sole();
    $instance->taskInstanceUsers()->create(['user_id' => $this->user->id]);

    $this->travel(3)->days();
    ReleaseOverdueTaskClaimsAction::make()->handle();

    Sanctum::actingAs($this->other);
    $this->getJson("/api/households/{$this->household->id}/task-instances")
        ->assertJsonPath('available.0.id', $instance->id)
        ->assertJsonPath('available.0.points', 11);

    Sanctum::actingAs($this->user);
    $this->getJson("/api/households/{$this->household->id}/task-instances")
        ->assertJsonPath('available.0.points', 10);
    $this->postJson("/api/households/{$this->household->id}/task-instances/{$instance->id}/claim")->assertOk();
    $this->postJson("/api/households/{$this->household->id}/task-instances/{$instance->id}/complete")->assertJsonPath('points', 10);
});

it('ignores non-recurring tasks', function () {
    recurringTask($this->household, ['is_recurring' => false, 'recurrence_interval' => null, 'recurrence_unit' => null]);

    $this->travel(48)->hours();

    expect(GenerateRecurringTaskInstancesAction::make()->handle())->toBe(0);
});

it('assigns the fixed member to every generated instance', function () {
    $task = recurringTask($this->household, ['assignment_mode' => 'fixed', 'fixed_user_id' => $this->other->id]);
    completeLatestInstance($task);

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
        completeLatestInstance($task);
        $this->travel(25)->hours();
        GenerateRecurringTaskInstancesAction::make()->handle();
        $assignees[] = $task->taskInstances()->latest('id')->first()->taskInstanceUsers()->value('user_id');
    }

    expect($assignees)->toBe([$this->user->id, $this->other->id, $this->user->id]);
});

it('leaves the generated instance unassigned without an assignment', function () {
    $task = recurringTask($this->household);
    completeLatestInstance($task);

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
