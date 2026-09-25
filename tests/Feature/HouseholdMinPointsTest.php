<?php

use App\Enums\RoleEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskUserWeight;
use App\Models\User;
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
        'name' => 'Task',
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

it('averages the member weights and ignores non recurring tasks', function () {
    $task = minPointsTask($this->household);
    minPointsTask($this->household, ['is_recurring' => false, 'recurrence_unit' => null, 'recurrence_interval' => null]);
    TaskUserWeight::create(['household_id' => $this->household->id, 'user_id' => $this->users[0]->id, 'task_id' => $task->id, 'weight' => 'hate']);
    TaskUserWeight::create(['household_id' => $this->household->id, 'user_id' => $this->users[1]->id, 'task_id' => $task->id, 'weight' => 'love']);

    expect($this->household->min_points)->toBe(50);
});
