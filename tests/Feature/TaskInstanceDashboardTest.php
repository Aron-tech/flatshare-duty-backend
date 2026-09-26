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
use App\Models\WeeklyPointGoal;
use Carbon\CarbonImmutable;
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
    $this->travelTo(WeeklyPointGoal::weekStartsAt());
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

it('shows the share of the points the user would get with the current claimers', function () {
    $joinable = dashboardInstance($this->household, $this->user, 2);
    $joinable->taskInstanceUsers()->create(['user_id' => dashboardUser()->id]);

    $shared = dashboardInstance($this->household, $this->user, 2);
    $shared->taskInstanceUsers()->create(['user_id' => $this->user->id]);
    $shared->taskInstanceUsers()->create(['user_id' => dashboardUser()->id]);

    $response = $this->getJson("/api/households/{$this->household->id}/task-instances")->assertOk();

    expect($response->json('available.0.points'))->toBe(5)
        ->and($response->json('available.0.claimers'))->toBe(2)
        ->and($response->json('claimed.0.points'))->toBe(5);
});

it('lets a member claim a task instance without weighting the task', function () {
    $instance = dashboardInstance($this->household, $this->user);

    $this->postJson("/api/households/{$this->household->id}/task-instances/{$instance->id}/claim")->assertOk();

    expect($instance->taskInstanceUsers()->count())->toBe(1);
});

it('returns the weekly points and holds back the ones covering the minimum from the spendable points', function () {
    Task::withoutEvents(fn () => Task::create([
        'household_id' => $this->household->id,
        'created_by' => $this->user->id,
        'name' => 'Weekly',
        'duration_minutes' => 30,
        'difficulty' => 'easy',
        'base_points' => 30,
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
    ]));
    PointTransaction::create([
        'household_id' => $this->household->id,
        'user_id' => $this->user->id,
        'amount' => 20,
        'balance_after' => 42,
        'type' => PointTransactionType::TASK_COMPLETION,
    ]);

    $this->getJson("/api/households/{$this->household->id}/me")
        ->assertOk()
        ->assertJsonPath('min_points', 30)
        ->assertJsonPath('weekly_points', 20)
        ->assertJsonPath('spendable_points', 22);
});

it('starts the week on Monday midnight in the week timezone', function () {
    expect(WeeklyPointGoal::weekStartsAt(CarbonImmutable::parse('2026-09-28 00:30', 'Europe/Budapest'))->toIso8601String())
        ->toBe('2026-09-27T22:00:00+00:00')
        ->and(WeeklyPointGoal::weekDate(CarbonImmutable::parse('2026-09-27 22:00', 'UTC')))->toBe('2026-09-28')
        ->and(WeeklyPointGoal::weekEndsAt(CarbonImmutable::parse('2026-10-19 00:00', 'Europe/Budapest'))->toIso8601String())
        ->toBe('2026-10-25T23:00:00+00:00');
});

it('counts a completion after Monday midnight in the week timezone to the new week', function () {
    $this->travelTo(WeeklyPointGoal::weekStartsAt()->addMinutes(30));
    foreach ([now()->subHour(), now()] as $created_at) {
        $transaction = PointTransaction::create([
            'household_id' => $this->household->id,
            'user_id' => $this->user->id,
            'amount' => 10,
            'balance_after' => 42,
            'type' => PointTransactionType::TASK_COMPLETION,
        ]);
        $transaction->forceFill(['created_at' => $created_at])->save();
    }

    $this->getJson("/api/households/{$this->household->id}/me")->assertOk()->assertJsonPath('weekly_points', 10);
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
