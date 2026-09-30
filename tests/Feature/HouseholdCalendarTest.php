<?php

use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function calendarUser(string $first_name = 'Test'): User
{
    return User::findOrFail(DB::table('users')->insertGetId([
        'workos_id' => 'user_'.uniqid(),
        'first_name' => $first_name,
        'last_name' => 'User',
        'email' => uniqid().'@example.com',
        'avatar' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
}

function calendarTask(Household $household, array $attributes = []): Task
{
    return Task::create([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => 'Task '.uniqid(),
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
        ...$attributes,
    ]);
}

function calendarQuery(Household $household, string $scope = 'mine', int $days = 30): string
{
    return "/api/households/{$household->id}/calendar?".http_build_query([
        'from' => now()->subDay()->toIso8601String(),
        'to' => now()->addDays($days)->toIso8601String(),
        'scope' => $scope,
    ]);
}

beforeEach(function () {
    $this->freezeSecond();
    $this->user = calendarUser('Anna');
    $this->other = calendarUser('Bela');
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000003', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::ADMIN]);
    $this->household->users()->attach($this->other->id, ['role' => RoleEnum::USER]);
});

it('projects the rotation of a recurring task and shows the viewer only their turns', function () {
    $task = calendarTask($this->household, ['assignment_mode' => 'rotating']);
    $task->rotations()->createMany([['user_id' => $this->user->id, 'rotation_order' => 0], ['user_id' => $this->other->id, 'rotation_order' => 1]]);
    $task->update(['last_assigned_user_id' => $this->user->id]);
    $task->taskInstances()->sole()->claimFor($this->user->id);

    Sanctum::actingAs($this->user);
    $household = $this->getJson(calendarQuery($this->household, 'household'))->assertOk()->json('events');

    // The claimed first instance, then Bela, Anna, Bela in the upcoming weeks.
    expect(collect($household)->pluck('status')->all())->toBe(['open', 'planned', 'planned', 'planned'])
        ->and(collect($household)->pluck('assignees.0.user_id')->all())->toBe([$this->user->id, $this->other->id, $this->user->id, $this->other->id])
        ->and($household[1]['at'])->toBe(now()->addWeeks(2)->utc()->toIso8601String());

    $mine = $this->getJson(calendarQuery($this->household))->assertOk()->json('events');
    expect(collect($mine)->pluck('status')->all())->toBe(['open', 'planned'])
        ->and(collect($mine)->every(fn (array $event): bool => $event['is_mine']))->toBeTrue();
});

it('shows the completed claims at their completion and hides the unassigned plans from the own calendar', function () {
    $task = calendarTask($this->household, ['is_recurring' => false, 'recurrence_interval' => null, 'recurrence_unit' => null]);
    $instance = $task->taskInstances()->sole();
    $instance->claimFor($this->user->id, ['completed_at' => now()]);
    $instance->update(['status' => TaskInstanceStatusEnum::ACCEPTED, 'completed_at' => now()]);
    calendarTask($this->household);

    Sanctum::actingAs($this->user);
    $events = $this->getJson(calendarQuery($this->household))->assertOk()->json('events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['status'])->toBe('completed')
        ->and($events[0]['at'])->toBe(now()->utc()->toIso8601String());
});

it('forbids the calendar of another household', function () {
    $stranger = calendarUser();

    Sanctum::actingAs($stranger);
    $this->getJson(calendarQuery($this->household))->assertForbidden();
    $this->postJson("/api/households/{$this->household->id}/calendar/subscription")->assertForbidden();
});

it('serves the iCalendar feed by the secret token until it is revoked', function () {
    $task = calendarTask($this->household, ['assignment_mode' => 'fixed', 'fixed_user_id' => $this->user->id, 'name' => 'Porszívózás, nappali']);
    $task->taskInstances()->sole()->claimFor($this->user->id);

    Sanctum::actingAs($this->user);
    $subscription = $this->postJson("/api/households/{$this->household->id}/calendar/subscription")->assertOk()->json();
    expect($subscription['mine']['webcal_url'])->toStartWith('webcal://')
        ->and($this->getJson("/api/households/{$this->household->id}/users")->json())->not->toHaveKey('household_users.0.calendar_token');

    $path = parse_url($subscription['household']['url'], PHP_URL_PATH).'?'.parse_url($subscription['household']['url'], PHP_URL_QUERY);
    $feed = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();

    expect($feed)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->toContain('SUMMARY:Porszívózás\, nappali (User Anna)')
        ->toContain('STATUS:TENTATIVE')
        ->and(collect(explode("\r\n", $feed))->every(fn (string $line): bool => strlen($line) <= 75))->toBeTrue();

    $this->deleteJson("/api/households/{$this->household->id}/calendar/subscription")->assertOk();
    $this->get($path)->assertNotFound();
});

it('revokes the feed when the member leaves the household', function () {
    $token = $this->other->membershipOf($this->household)->calendarToken();

    $this->other->membershipOf($this->household)->delete();

    $this->get("/api/calendar/{$token}.ics")->assertNotFound();
});
