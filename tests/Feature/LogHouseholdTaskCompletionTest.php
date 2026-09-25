<?php

use App\Enums\PointTransactionType;
use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Category;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function logUser(): User
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

function logTask(Household $household, array $attributes = []): Task
{
    return Task::create([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => 'Task '.uniqid(),
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->user = logUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::CHILD, 'points_balance' => 5]);
    Sanctum::actingAs($this->user);
});

it('lists only the non-recurring tasks of the household for any member', function () {
    $one_off = logTask($this->household, ['name' => 'Windows']);
    logTask($this->household, ['is_recurring' => true, 'recurrence_interval' => 1, 'recurrence_unit' => 'week']);

    $this->getJson("/api/households/{$this->household->id}/tasks/one-off")
        ->assertOk()
        ->assertJsonCount(1, 'tasks')
        ->assertJsonPath('tasks.0.id', $one_off->id)
        ->assertJsonPath('tasks.0.points', 10);
});

it('logs a non-recurring task as a new completed task instance and credits the points', function () {
    $task = logTask($this->household);
    $pending = $task->taskInstances()->sole();

    $this->postJson("/api/households/{$this->household->id}/tasks/{$task->id}/log")
        ->assertOk()
        ->assertJsonPath('points', 10)
        ->assertJsonPath('points_balance', 15);

    $logged = $task->taskInstances()->whereKeyNot($pending->id)->sole();
    expect($logged->status)->toBe(TaskInstanceStatusEnum::ACCEPTED)
        ->and($logged->completed_at)->not->toBeNull()
        ->and($logged->taskInstanceUsers()->sole()->completed_at)->not->toBeNull()
        ->and($pending->refresh()->status)->toBe(TaskInstanceStatusEnum::PENDING);

    $transaction = PointTransaction::sole();
    expect($transaction->type)->toBe(PointTransactionType::TASK_COMPLETION)
        ->and($transaction->task_instance_id)->toBe($logged->id);
});

it('does not log a recurring task or a task of another household', function () {
    $recurring = logTask($this->household, ['is_recurring' => true, 'recurrence_interval' => 1, 'recurrence_unit' => 'week']);
    $other_household = Household::create(['name' => 'Other', 'join_code' => '0000000002', 'created_by' => $this->user->id]);
    $foreign = logTask($other_household);

    $this->postJson("/api/households/{$this->household->id}/tasks/{$recurring->id}/log")->assertForbidden();
    $this->postJson("/api/households/{$this->household->id}/tasks/{$foreign->id}/log")->assertForbidden();

    expect(PointTransaction::count())->toBe(0);
});

it('stores a custom task as non-recurring and completes its only task instance', function () {
    $this->postJson("/api/households/{$this->household->id}/tasks/log", [
        'name' => 'Fix the shelf',
        'duration_minutes' => 20,
        'difficulty' => 'hard',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
    ])->assertOk()->assertJsonPath('points', 30);

    $task = Task::sole();
    $task_instance = TaskInstance::sole();
    expect($task->is_recurring)->toBeFalse()
        ->and($task_instance->status)->toBe(TaskInstanceStatusEnum::ACCEPTED)
        ->and($task_instance->taskInstanceUsers()->sole()->user_id)->toBe($this->user->id);
});

it('validates the custom task before logging it', function () {
    logTask($this->household, ['name' => 'Vacuum']);

    $this->postJson("/api/households/{$this->household->id}/tasks/log", [
        'name' => 'vacuum',
        'duration_minutes' => 20,
        'difficulty' => 'hard',
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('stores a task from a template and completes its only task instance', function () {
    $category = Category::create(['name' => ['hu' => 'Konyha', 'en' => 'Kitchen'], 'icon' => 'kitchen', 'color' => '#D87758']);
    $task_template = TaskTemplate::create([
        'name' => ['hu' => 'Mosogatás', 'en' => 'Dishes'],
        'description' => ['hu' => 'Leírás', 'en' => 'Description'],
        'icon' => 'sink',
        'category_id' => $category->id,
        'duration_minutes' => 20,
        'difficulty' => 'medium',
    ]);

    $this->postJson("/api/households/{$this->household->id}/tasks/templates/{$task_template->id}/log", ['max_user' => 1])
        ->assertOk();

    $task = Task::sole();
    expect($task->task_template_id)->toBe($task_template->id)
        ->and($task->is_recurring)->toBeFalse()
        ->and(TaskInstance::sole()->status)->toBe(TaskInstanceStatusEnum::ACCEPTED);
});
