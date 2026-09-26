<?php

use App\Actions\WeeklyPointGoal\CloseWeeklyPointGoalsAction;
use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Actions\WeeklyPointGoal\RemindWeeklyPointGoalsAction;
use App\Enums\ResetPeriodEnum;
use App\Enums\RoleEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyPointGoal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function settingsUser(): User
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

beforeEach(function () {
    $this->admin = settingsUser();
    $this->member = settingsUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->admin->id]);
    $this->household->users()->attach($this->admin->id, ['role' => RoleEnum::ADMIN]);
    $this->household->users()->attach($this->member->id, ['role' => RoleEnum::USER]);
});

it('defaults to a weekly reset on monday', function () {
    expect($this->household->resetPeriod())->toBe(ResetPeriodEnum::WEEKLY)
        ->and($this->household->resetDayOfWeek())->toBe(1)
        ->and($this->household->resetDayOfMonth())->toBe(1);
});

it('lets an admin set a weekly reset day', function () {
    Sanctum::actingAs($this->admin);

    $this->putJson("/api/households/{$this->household->id}/settings", [
        'reset_period' => 'weekly',
        'reset_day_of_week' => 5,
    ])->assertOk()->assertJsonPath('household.settings.reset.period', 'weekly');

    $household = $this->household->fresh();
    expect($household->resetPeriod())->toBe(ResetPeriodEnum::WEEKLY)
        ->and($household->resetDayOfWeek())->toBe(5);
});

it('lets an admin set a monthly reset day and keeps the weekly day', function () {
    $this->household->setData('reset.day_of_week', 3)->save();
    Sanctum::actingAs($this->admin);

    $this->putJson("/api/households/{$this->household->id}/settings", [
        'reset_period' => 'monthly',
        'reset_day_of_month' => 15,
    ])->assertOk();

    $household = $this->household->fresh();
    expect($household->resetPeriod())->toBe(ResetPeriodEnum::MONTHLY)
        ->and($household->resetDayOfMonth())->toBe(15)
        ->and($household->resetDayOfWeek())->toBe(3);
});

it('validates the reset settings', function (array $payload, string $error_field) {
    Sanctum::actingAs($this->admin);

    $this->putJson("/api/households/{$this->household->id}/settings", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error_field);
})->with([
    'unknown period' => [['reset_period' => 'daily'], 'reset_period'],
    'missing weekday' => [['reset_period' => 'weekly'], 'reset_day_of_week'],
    'invalid weekday' => [['reset_period' => 'weekly', 'reset_day_of_week' => 8], 'reset_day_of_week'],
    'missing month day' => [['reset_period' => 'monthly'], 'reset_day_of_month'],
    'invalid month day' => [['reset_period' => 'monthly', 'reset_day_of_month' => 31], 'reset_day_of_month'],
]);

it('forbids a non-admin member to change the settings', function () {
    Sanctum::actingAs($this->member);

    $this->putJson("/api/households/{$this->household->id}/settings", [
        'reset_period' => 'monthly',
        'reset_day_of_month' => 10,
    ])->assertForbidden();

    expect($this->household->fresh()->resetPeriod())->toBe(ResetPeriodEnum::WEEKLY);
});

function setResetSettings(Household $household, string $period, int $day): Household
{
    $household->setData('reset.period', $period)
        ->setData($period === 'weekly' ? 'reset.day_of_week' : 'reset.day_of_month', $day)
        ->save();

    return $household;
}

function settingsTask(Household $household, int $base_points): Task
{
    return Task::create([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => 'Dishes',
        'duration_minutes' => $base_points,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
    ]);
}

it('starts a weekly period on the chosen day of the week', function () {
    setResetSettings($this->household, 'weekly', 5);

    $starts_at = WeeklyPointGoal::weekStartsAt(CarbonImmutable::parse('2026-09-30 12:00', 'Europe/Budapest'), $this->household);

    expect(WeeklyPointGoal::weekDate($starts_at))->toBe('2026-09-25')
        ->and(WeeklyPointGoal::weekDate(WeeklyPointGoal::weekEndsAt($starts_at, $this->household)))->toBe('2026-10-02')
        ->and(WeeklyPointGoal::weekDate(WeeklyPointGoal::weekStartsAt(CarbonImmutable::parse('2026-10-02 00:00', 'Europe/Budapest'), $this->household)))->toBe('2026-10-02');
});

it('starts a monthly period on the chosen day of the month', function () {
    setResetSettings($this->household, 'monthly', 15);

    $before_reset_day = WeeklyPointGoal::weekStartsAt(CarbonImmutable::parse('2026-03-10 12:00', 'Europe/Budapest'), $this->household);
    $on_reset_day = WeeklyPointGoal::weekStartsAt(CarbonImmutable::parse('2026-03-15 00:00', 'Europe/Budapest'), $this->household);

    expect(WeeklyPointGoal::weekDate($before_reset_day))->toBe('2026-02-15')
        ->and(WeeklyPointGoal::weekDate(WeeklyPointGoal::weekEndsAt($before_reset_day, $this->household)))->toBe('2026-03-15')
        ->and(WeeklyPointGoal::weekDate($on_reset_day))->toBe('2026-03-15')
        ->and(WeeklyPointGoal::weekEndsAt($on_reset_day, $this->household)->toIso8601String())->toBe('2026-04-14T22:00:00+00:00');
});

it('scales the minimum points of a monthly goal to the length of the month', function () {
    setResetSettings($this->household, 'monthly', 1);
    $this->travelTo(CarbonImmutable::parse('2027-02-01 00:00', 'Europe/Budapest'));
    settingsTask($this->household, 70);

    $goals = RecalculateWeeklyPointGoalsAction::run($this->household);

    // 70 points a week split between 2 members, for the 28 days (4 weeks) of February.
    expect($goals->get($this->admin->id)->target_points)->toBe(140);
});

it('closes a monthly goal only after the chosen day of the next month', function () {
    Queue::fake();
    setResetSettings($this->household, 'monthly', 10);
    $this->travelTo(CarbonImmutable::parse('2026-10-10 00:00', 'Europe/Budapest'));
    settingsTask($this->household, 70);
    RecalculateWeeklyPointGoalsAction::run($this->household);

    $this->travelTo(CarbonImmutable::parse('2026-11-09 00:05', 'Europe/Budapest'));
    CloseWeeklyPointGoalsAction::run();
    expect(WeeklyPointGoal::whereDate('week_starts_at', '2026-10-10')->whereNotNull('closed_at')->exists())->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-11-10 00:05', 'Europe/Budapest'));
    CloseWeeklyPointGoalsAction::run();
    expect(WeeklyPointGoal::whereDate('week_starts_at', '2026-10-10')->whereNull('closed_at')->exists())->toBeFalse()
        ->and(WeeklyPointGoal::whereDate('week_starts_at', '2026-11-10')->count())->toBe(2);
});

it('reminds the members only on the last day of a monthly period', function () {
    Queue::fake();
    setResetSettings($this->household, 'monthly', 1);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 00:00', 'Europe/Budapest'));
    settingsTask($this->household, 70);

    $this->travelTo(CarbonImmutable::parse('2026-10-24 16:00', 'Europe/Budapest'));
    expect(RemindWeeklyPointGoalsAction::run())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-31 16:00', 'Europe/Budapest'));
    expect(RemindWeeklyPointGoalsAction::run())->toBe(2);
});

it('drops the open goals of the old period when the reset settings change', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'Europe/Budapest'));
    settingsTask($this->household, 70);
    RecalculateWeeklyPointGoalsAction::run($this->household);
    WeeklyPointGoal::create(['household_id' => $this->household->id, 'user_id' => $this->admin->id, 'week_starts_at' => '2026-10-05', 'target_points' => 35, 'closed_at' => now()]);
    Sanctum::actingAs($this->admin);

    $this->putJson("/api/households/{$this->household->id}/settings", [
        'reset_period' => 'monthly',
        'reset_day_of_month' => 1,
    ])->assertOk();

    expect(WeeklyPointGoal::whereDate('week_starts_at', '2026-10-12')->exists())->toBeFalse()
        ->and(WeeklyPointGoal::whereDate('week_starts_at', '2026-10-05')->exists())->toBeTrue()
        ->and(WeeklyPointGoal::whereDate('week_starts_at', '2026-10-01')->count())->toBe(2);
});
