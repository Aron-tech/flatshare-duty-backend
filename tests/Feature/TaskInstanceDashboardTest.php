<?php

use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskUserWeight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function dashboardUser(): User
{
    $id = DB::table('users')->insertGetId([
        'workos_id' => 'user_'.uniqid(),
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => uniqid().'@example.com',
        'avatar' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::findOrFail($id);
}

function dashboardInstance(Household $household, User $user, int $max_user = 1, ?string $due_at = null): TaskInstance
{
    $task = Task::withoutEvents(fn () => Task::create([
        'household_id' => $household->id,
        'created_by' => $user->id,
        'name' => 'Task '.uniqid(),
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'base_points' => 10,
        'max_user' => $max_user,
    ]));

    return TaskInstance::create([
        'task_id' => $task->id,
        'household_id' => $household->id,
        'status' => TaskInstanceStatusEnum::PENDING,
        'due_at' => $due_at,
    ]);
}

function weighTask(TaskInstance $instance, User $user): void
{
    TaskUserWeight::create([
        'household_id' => $instance->household_id,
        'user_id' => $user->id,
        'task_id' => $instance->task_id,
        'weight' => 'neutral',
    ]);
}

beforeEach(function () {
    $this->travelTo(now()->startOfWeek());
    $this->user = dashboardUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::USER, 'points_balance' => 42]);
    Sanctum::actingAs($this->user);
});

it('returns the points balance and the calculated minimum points', function () {
    Task::withoutEvents(fn () => Task::create([
        'household_id' => $this->household->id,
        'created_by' => $this->user->id,
        'name' => 'Weekly',
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'base_points' => 80,
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
    ]));

    $this->getJson("/api/households/{$this->household->id}/me")
        ->assertOk()
        ->assertJsonPath('household_user.points_balance', 42)
        ->assertJsonPath('min_points', 80);
});

it('splits task instances into available and claimed', function () {
    $available = dashboardInstance($this->household, $this->user);
    $claimed = dashboardInstance($this->household, $this->user, 2, now()->addDay()->toDateTimeString());
    $claimed->taskInstanceUsers()->create(['user_id' => $this->user->id]);

    $full = dashboardInstance($this->household, $this->user);
    $full->taskInstanceUsers()->create(['user_id' => dashboardUser()->id]);

    $completed_part = dashboardInstance($this->household, $this->user, 2);
    $completed_part->taskInstanceUsers()->create(['user_id' => $this->user->id, 'completed_at' => now()]);

    $response = $this->getJson("/api/households/{$this->household->id}/task-instances")->assertOk();

    expect(collect($response->json('available'))->pluck('id')->all())->toBe([$available->id])
        ->and(collect($response->json('claimed'))->pluck('id')->all())->toBe([$claimed->id]);
});

it('does not let a task instance be claimed before the user weighed the task', function () {
    $instance = dashboardInstance($this->household, $this->user);

    $this->postJson("/api/households/{$this->household->id}/task-instances/{$instance->id}/claim")->assertForbidden();

    expect($instance->taskInstanceUsers()->count())->toBe(0);
});

it('claims a task instance only once', function () {
    $instance = dashboardInstance($this->household, $this->user);
    $url = "/api/households/{$this->household->id}/task-instances/{$instance->id}/claim";
    weighTask($instance, $this->user);

    $this->postJson($url)->assertOk();
    $this->postJson($url)->assertForbidden();
});

it('does not let a non member see or claim task instances', function () {
    $instance = dashboardInstance($this->household, $this->user);
    Sanctum::actingAs(dashboardUser());

    $this->getJson("/api/households/{$this->household->id}/task-instances")->assertForbidden();
    $this->postJson("/api/households/{$this->household->id}/task-instances/{$instance->id}/claim")->assertForbidden();
    $this->getJson("/api/households/{$this->household->id}/me")->assertForbidden();
});
