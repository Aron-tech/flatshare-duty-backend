<?php

use App\Actions\CalculateHouseholdMinPointsAction;
use App\Enums\RoleEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskUserWeight;
use App\Models\User;
use App\Models\WeeklyPointGoal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function minPointsUser(): User
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

function minPointsTask(Household $household, array $attributes = []): Task
{
    return Task::withoutEvents(fn () => Task::create(array_merge([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => 'Task '.uniqid(),
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'base_points' => 100,
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
        'max_user' => 1,
    ], $attributes)));
}

beforeEach(function () {
    $this->users = collect([minPointsUser(), minPointsUser()]);
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000002', 'created_by' => $this->users[0]->id]);
    foreach ($this->users as $user) {
        $this->household->users()->attach($user->id, ['role' => RoleEnum::USER, 'points_balance' => 0]);
    }
});

it('is zero without tasks', function () {
    expect($this->household->min_points)->toBe(0);
});

it('splits the weekly pool of the tasks between the members', function () {
    minPointsTask($this->household);
    minPointsTask($this->household, ['recurrence_unit' => 'day', 'base_points' => 10]);

    expect($this->household->min_points)->toBe(85);
});

it('counts a task claimable by many members once, as the claimers share its points', function () {
    minPointsTask($this->household, ['max_user' => 2]);

    expect($this->household->min_points)->toBe(50);
});

it('averages the member weights and ignores non recurring tasks without an instance', function () {
    $task = minPointsTask($this->household);
    minPointsTask($this->household, ['is_recurring' => false, 'recurrence_unit' => null, 'recurrence_interval' => null]);
    TaskUserWeight::create(['household_id' => $this->household->id, 'user_id' => $this->users[0]->id, 'task_id' => $task->id, 'weight' => 'hate']);
    TaskUserWeight::create(['household_id' => $this->household->id, 'user_id' => $this->users[1]->id, 'task_id' => $task->id, 'weight' => 'love']);

    expect($this->household->min_points)->toBe(50);
});

function minPointsInstantTask(Household $household, array $instance = []): Task
{
    $task = minPointsTask($household, ['is_recurring' => false, 'recurrence_unit' => null, 'recurrence_interval' => null]);
    $task->taskInstances()->create(array_merge(['household_id' => $household->id, 'status' => 'pending'], $instance));

    return $task;
}

it('counts the open instant tasks in full', function () {
    minPointsInstantTask($this->household);

    expect($this->household->min_points)->toBe(50);
});

it('counts the instant tasks completed during the week but not the ones completed before', function () {
    minPointsInstantTask($this->household, ['status' => 'accepted', 'completed_at' => now()]);
    minPointsInstantTask($this->household, ['status' => 'accepted', 'completed_at' => now()->subWeeks(2)]);

    expect($this->household->min_points)->toBe(50);
});

it('carries an unfinished instant task over to the next week', function () {
    $task = minPointsInstantTask($this->household);
    $task->taskInstances()->update(['created_at' => now()->subWeeks(3)]);

    expect($this->household->min_points)->toBe(50);
});

function minPointsCloseWeek(Household $household, int $weeks_ago): void
{
    WeeklyPointGoal::create([
        'household_id' => $household->id,
        'user_id' => $household->created_by,
        'week_starts_at' => WeeklyPointGoal::weekDate(WeeklyPointGoal::weekStartsAt(now()->subWeeks($weeks_ago), $household)),
        'target_points' => 0,
        'closed_at' => now(),
    ]);
}

function minPointsCompletedInstantTask(Household $household, CarbonInterface $created_at, int $base_points = 100): void
{
    $task = minPointsTask($household, ['is_recurring' => false, 'recurrence_unit' => null, 'recurrence_interval' => null, 'base_points' => $base_points]);
    $task->taskInstances()->create(['household_id' => $household->id, 'status' => 'accepted', 'completed_at' => $created_at]);
    $task->taskInstances()->update(['created_at' => $created_at]);
}

describe('learning the instant tasks', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00', config('app.week_timezone')));
        $this->household->forceFill(['created_at' => now()->subWeeks(6)])->save();
    });

    it('expects as many instant task points as the previous closed weeks had', function () {
        minPointsCompletedInstantTask($this->household, now()->subWeek());

        expect($this->household->min_points)->toBe(0);

        minPointsCloseWeek($this->household, 1);

        expect($this->household->refresh()->min_points)->toBe(50);
    });

    it('weighs the recent weeks more', function () {
        minPointsCompletedInstantTask($this->household, now()->subWeek());
        minPointsCompletedInstantTask($this->household, now()->subWeeks(2), 400);
        minPointsCloseWeek($this->household, 1);
        minPointsCloseWeek($this->household, 2);

        expect($this->household->min_points)->toBe(100);
    });

    it('counts the instant tasks of the week when they are worth more than the learned points', function () {
        minPointsCompletedInstantTask($this->household, now()->subWeek());
        minPointsCloseWeek($this->household, 1);
        minPointsInstantTask($this->household);
        minPointsInstantTask($this->household);

        expect($this->household->min_points)->toBe(100);
    });

    it('counts only the real instant tasks of a period that has ended', function () {
        minPointsCompletedInstantTask($this->household, now()->subWeeks(2));
        minPointsCloseWeek($this->household, 2);

        expect(CalculateHouseholdMinPointsAction::run($this->household, WeeklyPointGoal::weekStartsAt(now()->subWeek(), $this->household)))->toBe(0);
    });

    it('does not learn from a week that started before the household was created', function () {
        $this->household->forceFill(['created_at' => now()->subWeek()])->save();
        minPointsCompletedInstantTask($this->household, now()->subWeek());
        minPointsCloseWeek($this->household, 1);

        expect($this->household->refresh()->min_points)->toBe(0);
    });
});
