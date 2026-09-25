<?php

use App\Actions\TaskWeightNotification\NotifyUnweightedTasksAction;
use App\Enums\RoleEnum;
use App\Enums\TaskUserWeightEnum;
use App\Jobs\SendExpoPushNotificationsJob;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskUserWeight;
use App\Models\TaskWeightNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function weightReminderUser(string $token): User
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
    $user->pushTokens()->create(['token' => "ExpoPushToken[{$token}]", 'platform' => 'ios']);

    return $user;
}

function weightReminderTask(Household $household, string $name): Task
{
    return Task::create([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => $name,
        'duration_minutes' => 10,
        'difficulty' => 'easy',
    ]);
}

beforeEach(function () {
    Queue::fake();
    $this->admin = weightReminderUser('admin');
    $this->member = weightReminderUser('member');
    $this->household = Household::create(['name' => 'Otthon', 'join_code' => '0000000001', 'created_by' => $this->admin->id]);
    $this->household->users()->attach($this->admin->id, ['role' => RoleEnum::ADMIN]);
    $this->household->users()->attach($this->member->id, ['role' => RoleEnum::USER]);
});

it('does not notify when a task is created', function () {
    weightReminderTask($this->household, 'Mosogatás');

    Queue::assertNothingPushed();
});

it('sends one summary per member with all unweighted tasks', function () {
    weightReminderTask($this->household, 'Mosogatás');
    weightReminderTask($this->household, 'Porszívózás');

    expect(NotifyUnweightedTasksAction::run())->toBe(2);

    Queue::assertPushed(SendExpoPushNotificationsJob::class, 2);
    Queue::assertPushed(SendExpoPushNotificationsJob::class, fn ($job) => $job->tokens === ['ExpoPushToken[member]']
        && $job->title === 'Otthon – új feladatok'
        && str_contains($job->body, 'Mosogatás, Porszívózás')
        && $job->data['route'] === '/chores');
    expect(TaskWeightNotification::count())->toBe(4);
});

it('notifies only once per task', function () {
    weightReminderTask($this->household, 'Mosogatás');
    NotifyUnweightedTasksAction::run();

    weightReminderTask($this->household, 'Porszívózás');
    expect(NotifyUnweightedTasksAction::run())->toBe(2);

    Queue::assertPushed(SendExpoPushNotificationsJob::class, fn ($job) => str_contains($job->body, 'Porszívózás')
        && ! str_contains($job->body, 'Mosogatás'));
    expect(NotifyUnweightedTasksAction::run())->toBe(0);
});

it('skips tasks the member already weighted', function () {
    $task = weightReminderTask($this->household, 'Mosogatás');
    TaskUserWeight::create(['household_id' => $this->household->id, 'user_id' => $this->member->id, 'task_id' => $task->id, 'weight' => TaskUserWeightEnum::LIKE]);

    expect(NotifyUnweightedTasksAction::run())->toBe(1);

    Queue::assertPushed(SendExpoPushNotificationsJob::class, fn ($job) => $job->tokens === ['ExpoPushToken[admin]']);
    Queue::assertNotPushed(SendExpoPushNotificationsJob::class, fn ($job) => $job->tokens === ['ExpoPushToken[member]']);
});

it('waits for users without a push token', function () {
    $this->member->pushTokens()->delete();
    weightReminderTask($this->household, 'Mosogatás');

    expect(NotifyUnweightedTasksAction::run())->toBe(1)
        ->and(TaskWeightNotification::where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('shortens a long task list', function () {
    foreach (range(1, 7) as $index) {
        weightReminderTask($this->household, "Feladat {$index}");
    }

    NotifyUnweightedTasksAction::run();

    Queue::assertPushed(SendExpoPushNotificationsJob::class, fn ($job) => str_ends_with($job->body, 'Feladat 5 és még 2 további'));
});

it('is scheduled hourly', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('tasks:notify-unweighted');
});
