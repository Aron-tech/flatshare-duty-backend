<?php

use App\Actions\WeeklyPointGoal\AssignWeeklyGoalPenaltyAction;
use App\Actions\WeeklyPointGoal\CloseWeeklyPointGoalsAction;
use App\Actions\WeeklyPointGoal\RemindWeeklyPointGoalsAction;
use App\Enums\PointTransactionType;
use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Jobs\SendExpoPushNotificationsJob;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\Task;
use App\Models\TaskInstanceUser;
use App\Models\User;
use App\Models\WeeklyPointGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function goalUser(): User
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

function goalTask(Household $household, string $name, int $base_points, array $attributes = []): Task
{
    return Task::create([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => $name,
        'duration_minutes' => $base_points,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
        ...$attributes,
    ]);
}

function earnPoints(Household $household, User $user, int $amount): void
{
    PointTransaction::create([
        'household_id' => $household->id,
        'user_id' => $user->id,
        'amount' => $amount,
        'balance_after' => $amount,
        'type' => PointTransactionType::TASK_COMPLETION,
    ]);
}

function goalOf(Household $household, User $user): WeeklyPointGoal
{
    return WeeklyPointGoal::where('household_id', $household->id)->where('user_id', $user->id)->latest('week_starts_at')->firstOrFail();
}

beforeEach(function () {
    Queue::fake();
    $this->travelTo(WeeklyPointGoal::weekStartsAt());
    $this->user = goalUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::ADMIN]);
    Sanctum::actingAs($this->user);
});

it('recalculates the weekly minimum points when a task is added', function () {
    goalTask($this->household, 'Dishes', 70);

    expect(goalOf($this->household, $this->user)->target_points)->toBe(70);

    $this->travel(3)->days();
    $this->postJson("/api/households/{$this->household->id}/tasks", [
        'name' => 'Vacuum',
        'duration_minutes' => 70,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
    ])->assertOk();

    // A csütörtökön hozzáadott feladatból csak a hét hátralévő 4/7 része számít.
    expect(goalOf($this->household, $this->user)->target_points)->toBe(70 + 40);
    $this->getJson("/api/households/{$this->household->id}/me")->assertJsonPath('min_points', 110);
});

it('recalculates the weekly minimum points when a task is deleted', function () {
    goalTask($this->household, 'Dishes', 70);
    $task = goalTask($this->household, 'Vacuum', 70);

    $this->deleteJson("/api/households/{$this->household->id}/tasks/{$task->id}")->assertOk();

    expect(goalOf($this->household, $this->user)->target_points)->toBe(70);
});

it('prorates the goal of a member who joined during the week', function () {
    goalTask($this->household, 'Dishes', 140);
    $this->travel(3)->days();
    $member = goalUser();
    Sanctum::actingAs($member);

    $this->postJson('/api/households/join', ['code' => $this->household->join_code])->assertOk();

    expect(goalOf($this->household, $this->user)->target_points)->toBe(70)
        ->and(goalOf($this->household, $member)->target_points)->toBe(40);
});

it('does not penalize a member who reached the tolerance', function () {
    goalTask($this->household, 'Dishes', 100);
    earnPoints($this->household, $this->user, 90);

    $this->travel(1)->week();
    CloseWeeklyPointGoalsAction::run();

    $goal = WeeklyPointGoal::whereDate('week_starts_at', WeeklyPointGoal::weekDate(now()->subWeek()))->sole();
    expect($goal->closed_at)->not->toBeNull()
        ->and($goal->earned_points)->toBe(90)
        ->and($goal->shortfall_points)->toBe(10)
        ->and(TaskInstanceUser::whereNotNull('weekly_point_goal_id')->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('deducts the points covering the minimum when the week is closed and keeps the extra points', function (int $earned, int $balance, int $expected_balance) {
    goalTask($this->household, 'Dishes', 100);
    earnPoints($this->household, $this->user, $earned);
    $this->household->householdUsers()->update(['points_balance' => $balance]);

    $this->travel(1)->week();
    CloseWeeklyPointGoalsAction::run();
    CloseWeeklyPointGoalsAction::run();

    expect($this->household->householdUsers()->sole()->points_balance)->toBe($expected_balance)
        ->and(PointTransaction::where('type', PointTransactionType::WEEKLY_GOAL_SETTLEMENT)->sum('amount'))->toBe($balance - $expected_balance);
})->with([
    'extra points' => [130, 150, 50],
    'within the tolerance' => [95, 95, 0],
    'below the minimum' => [40, 60, 20],
]);

it('assigns the cheapest task worth more than the shortfall below the tolerance when the minimum is missed', function () {
    goalTask($this->household, 'Dishes', 100);
    goalTask($this->household, 'Windows', 30, ['is_recurring' => false, 'recurrence_interval' => null, 'recurrence_unit' => null]);
    goalTask($this->household, 'Laundry', 50, ['is_recurring' => false, 'recurrence_interval' => null, 'recurrence_unit' => null]);
    earnPoints($this->household, $this->user, 140);

    $this->travel(1)->week();
    CloseWeeklyPointGoalsAction::run();
    CloseWeeklyPointGoalsAction::run();

    $penalty = TaskInstanceUser::whereNotNull('weekly_point_goal_id')->with('taskInstance.task')->sole();
    expect($penalty->user_id)->toBe($this->user->id)
        ->and($penalty->taskInstance->task->name)->toBe('Windows')
        ->and($penalty->penalty_points)->toBe(22)
        ->and($penalty->taskInstance->due_at->equalTo(WeeklyPointGoal::weekEndsAt(WeeklyPointGoal::weekStartsAt())))->toBeTrue()
        ->and($penalty->weeklyPointGoal->shortfall_points)->toBe(40);
    Queue::assertPushed(SendExpoPushNotificationsJob::class, 1);
    Queue::assertPushed(SendExpoPushNotificationsJob::class, fn ($job) => str_contains($job->body, 'Windows'));
});

it('assigns the most valuable tasks when no single task covers the shortfall', function () {
    $tasks = collect([10, 30, 20])->map(fn (int $points, int $index) => [
        'task' => new Task(['name' => "Task {$index}"]),
        'points' => $points,
    ]);

    $picked = AssignWeeklyGoalPenaltyAction::make()->pickTasks($tasks, 45);

    expect($picked->pluck('name')->all())->toBe(['Task 1', 'Task 2']);
});

it('grows the penalty from zero at the tolerance', function (int $earned, int $expected) {
    $goal = new WeeklyPointGoal(['target_points' => 100]);

    expect($goal->penaltyPoints($earned))->toBe($expected);
})->with([
    'reached the tolerance' => [90, 0],
    'just below the tolerance' => [89, 1],
    'nothing earned' => [0, 90],
]);

it('pays only the points of a penalty task above the shortfall it covers', function () {
    goalTask($this->household, 'Dishes', 100);
    $this->travel(1)->week();
    CloseWeeklyPointGoalsAction::run();
    $penalty = TaskInstanceUser::whereNotNull('weekly_point_goal_id')->sole();

    $this->getJson("/api/households/{$this->household->id}/task-instances")
        ->assertJsonPath('claimed.0.is_penalty', true)
        ->assertJsonPath('claimed.0.points', 10);

    $this->postJson("/api/households/{$this->household->id}/task-instances/{$penalty->task_instance_id}/complete")
        ->assertOk()
        ->assertJsonPath('points', 10)
        ->assertJsonPath('weekly_points', 10);

    expect(PointTransaction::sole()->type)->toBe(PointTransactionType::PENALTY_TASK_COMPLETION)
        ->and($penalty->taskInstance->refresh()->status)->toBe(TaskInstanceStatusEnum::ACCEPTED);
    $this->getJson("/api/households/{$this->household->id}/stats")
        ->assertJsonPath('penalties.0.id', "weekly-goal-{$penalty->id}")
        ->assertJsonPath('penalties.0.status', 'resolved');
});

it('reminds the members who are behind only once a week', function () {
    $member = goalUser();
    $this->household->users()->attach($member->id, ['role' => RoleEnum::USER]);
    goalTask($this->household, 'Dishes', 100);
    earnPoints($this->household, $this->user, 50);

    $this->travel(6)->days();
    expect(RemindWeeklyPointGoalsAction::run())->toBe(1)
        ->and(RemindWeeklyPointGoalsAction::run())->toBe(0);

    Queue::assertPushed(SendExpoPushNotificationsJob::class, fn ($job) => $job->tokens === ["ExpoPushToken[{$member->id}]"]
        && str_contains($job->body, '50'));
});
