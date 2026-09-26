<?php

use App\Enums\PointTransactionType;
use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;
use App\Models\WeeklyPointGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function statsUser(string $first_name = 'Test'): User
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

function statsInstance(Household $household, array $attributes = []): TaskInstance
{
    $task = Task::firstOrCreate(['household_id' => $household->id, 'name' => 'Dishes'], [
        'created_by' => $household->created_by,
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
    ]);

    return TaskInstance::create([
        'task_id' => $task->id,
        'household_id' => $household->id,
        'status' => TaskInstanceStatusEnum::PENDING,
        ...$attributes,
    ]);
}

function statsTransaction(Household $household, User $user, int $amount, PointTransactionType $type = PointTransactionType::TASK_COMPLETION, ?TaskInstance $task_instance = null): PointTransaction
{
    return PointTransaction::create([
        'household_id' => $household->id,
        'user_id' => $user->id,
        'amount' => $amount,
        'balance_after' => $amount,
        'type' => $type,
        'task_instance_id' => $task_instance?->id,
    ]);
}

beforeEach(function () {
    $this->travelTo(WeeklyPointGoal::weekStartsAt());
    $this->user = statsUser('Me');
    $this->other = statsUser('Other');
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::ADMIN]);
    $this->household->users()->attach($this->other->id, ['role' => RoleEnum::USER]);
    Sanctum::actingAs($this->user);
});

it('returns the cycle totals and the members ordered by points', function () {
    $instance = statsInstance($this->household, ['status' => TaskInstanceStatusEnum::ACCEPTED, 'completed_at' => now()]);
    statsTransaction($this->household, $this->user, 10, task_instance: $instance);
    statsTransaction($this->household, $this->other, 30);
    $this->travel(-8)->days();
    statsTransaction($this->household, $this->user, 100);
    $this->travelBack();

    $response = $this->getJson("/api/households/{$this->household->id}/stats")->assertOk();

    $min_points = $this->household->min_points;
    $response
        ->assertJsonPath('cycle.number', now()->isoWeek())
        ->assertJsonPath('cycle.total_points', 40)
        ->assertJsonPath('cycle.target_points', $min_points * 2)
        ->assertJsonPath('balance_percent', 50)
        ->assertJsonPath('members.0.user_id', $this->other->id)
        ->assertJsonPath('members.0.points', 30)
        ->assertJsonPath('members.0.is_me', false)
        ->assertJsonPath('members.1.points', 10)
        ->assertJsonPath('members.1.role', 'admin')
        ->assertJsonPath('members.1.target', $min_points)
        ->assertJsonPath('members.1.is_me', true);

    expect($response->json('activity'))->toHaveCount(3)
        ->and($response->json('activity.2.task_name'))->toBe('Dishes')
        ->and($response->json('activity.2.is_me'))->toBeTrue();
});

it('lists the overdue claims and the charged penalties', function () {
    $overdue = statsInstance($this->household, ['due_at' => now()->subDay()]);
    // A határidő előtt vállalta; a határidő utáni vállalás mentés, nem büntetés.
    $overdue->taskInstanceUsers()->create(['user_id' => $this->other->id])->forceFill(['created_at' => now()->subDays(2)])->save();
    statsInstance($this->household, ['due_at' => now()->subDay()])->taskInstanceUsers()->create(['user_id' => $this->user->id]);
    statsInstance($this->household, ['due_at' => now()->addDay()])->taskInstanceUsers()->create(['user_id' => $this->other->id]);

    $missed = statsInstance($this->household, ['status' => TaskInstanceStatusEnum::EXPIRED]);
    $penalty = statsTransaction($this->household, $this->user, 5, PointTransactionType::MISSED_TASK_PENALTY, $missed);

    $response = $this->getJson("/api/households/{$this->household->id}/stats")->assertOk();

    expect($response->json('penalties'))->toHaveCount(2)
        ->and($response->json('penalties.0.status'))->toBe('pending')
        ->and($response->json('penalties.0.user_id'))->toBe($this->other->id)
        ->and($response->json('penalties.0.due_at'))->not->toBeNull()
        ->and($response->json('penalties.1.id'))->toBe("resolved-{$penalty->id}")
        ->and($response->json('penalties.1.status'))->toBe('resolved');
});

it('returns a full balance when nobody earned points yet', function () {
    $this->getJson("/api/households/{$this->household->id}/stats")
        ->assertOk()
        ->assertJsonPath('balance_percent', 100)
        ->assertJsonPath('cycle.total_points', 0);
});

it('does not let a non member see the stats', function () {
    Sanctum::actingAs(statsUser());

    $this->getJson("/api/households/{$this->household->id}/stats")->assertForbidden();
});

it('pages the household activity with a cursor', function () {
    foreach (range(1, 5) as $amount) {
        statsTransaction($this->household, $this->user, $amount);
    }
    statsTransaction($this->household, $this->user, 99, PointTransactionType::MISSED_TASK_PENALTY);

    $first = $this->getJson("/api/households/{$this->household->id}/activity?limit=2")->assertOk();
    expect($first->json('data.*.points'))->toBe([5, 4]);

    $second = $this->getJson("/api/households/{$this->household->id}/activity?limit=2&cursor={$first->json('next_cursor')}")->assertOk();
    expect($second->json('data.*.points'))->toBe([3, 2]);

    $last = $this->getJson("/api/households/{$this->household->id}/activity?limit=2&cursor={$second->json('next_cursor')}")->assertOk();
    expect($last->json('data.*.points'))->toBe([1])
        ->and($last->json('next_cursor'))->toBeNull();
});

it('does not list the activity of a household the user is not a member of', function () {
    $stranger = Household::create(['name' => 'Other', 'join_code' => '0000000002', 'created_by' => $this->other->id]);

    $this->getJson("/api/households/{$stranger->id}/activity")->assertForbidden();
});
