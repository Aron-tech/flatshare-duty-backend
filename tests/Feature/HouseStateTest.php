<?php

use App\Enums\CharacterEnum;
use App\Enums\PointTransactionType;
use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Category;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function houseUser(string $first_name = 'Test', ?CharacterEnum $character = null): User
{
    return User::findOrFail(DB::table('users')->insertGetId([
        'workos_id' => 'user_'.uniqid(),
        'first_name' => $first_name,
        'last_name' => 'User',
        'email' => uniqid().'@example.com',
        'avatar' => '',
        'character' => $character?->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
}

function houseCategory(string $icon): Category
{
    return Category::create(['name' => ['en' => ucfirst($icon), 'hu' => $icon], 'icon' => $icon, 'color' => '#000000', 'sort_order' => 1]);
}

function houseInstance(Household $household, ?Category $category, array $attributes = []): TaskInstance
{
    $task = Task::create([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => 'Task '.uniqid(),
        'category_id' => $category?->id,
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'is_recurring' => false,
    ]);
    // TaskObserver creates the first instance (no due date), so reuse it.
    $instance = $task->taskInstances()->firstOrFail();
    $instance->update($attributes);

    return $instance;
}

beforeEach(function () {
    // Wednesday morning, so "due today" does not slip into the next day.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Europe/Budapest'));
    $this->user = houseUser('Me');
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::ADMIN]);
    Sanctum::actingAs($this->user);
});

it('rates the mess of each task category from its open instances', function () {
    $kitchen = houseCategory('utensils');
    $bathroom = houseCategory('bath');
    $laundry = houseCategory('shirt');
    $trash = houseCategory('trash-2');

    houseInstance($this->household, $kitchen, ['due_at' => now()->subHour()]);
    houseInstance($this->household, $kitchen, ['due_at' => now()->subHours(2)]);
    houseInstance($this->household, $bathroom, ['due_at' => now()->subHour()]);
    houseInstance($this->household, $laundry, ['due_at' => now()->addHours(3)]);
    houseInstance($this->household, $trash, ['due_at' => now()->addDays(3)]);
    houseInstance($this->household, null, ['due_at' => now()->subDays(3)]);
    houseInstance($this->household, $trash, ['status' => TaskInstanceStatusEnum::ACCEPTED, 'completed_at' => now(), 'due_at' => now()->subDays(5)]);

    $zones = collect($this->getJson("/api/households/{$this->household->id}/house")->assertOk()->json('zones'))
        ->keyBy(fn (array $zone): string => $zone['category_icon'] ?? 'none');

    expect($zones['utensils'])->toMatchArray(['overdue' => 2, 'open' => 2, 'mess_level' => 3])
        ->and($zones['bath'])->toMatchArray(['overdue' => 1, 'mess_level' => 2])
        ->and($zones['shirt'])->toMatchArray(['due_today' => 1, 'overdue' => 0, 'mess_level' => 1])
        ->and($zones['trash-2'])->toMatchArray(['open' => 1, 'mess_level' => 0])
        // A single task overdue by more than 48 hours is already full mess.
        ->and($zones['none'])->toMatchArray(['category_id' => null, 'overdue' => 1, 'mess_level' => 3]);
});

it('is happy when nothing is open and the household keeps the pace', function () {
    $this->getJson("/api/households/{$this->household->id}/house")
        ->assertOk()
        ->assertJsonPath('mood.score', 100)
        ->assertJsonPath('mood.band', 'happy')
        ->assertJsonPath('mood.behind_pace', false)
        ->assertJsonPath('zones', []);
});

it('gets sad when the house is a mess and the household is behind the pace', function () {
    $kitchen = houseCategory('utensils');
    $bathroom = houseCategory('bath');
    foreach ([$kitchen, $kitchen, $bathroom, $bathroom] as $category) {
        houseInstance($this->household, $category, ['due_at' => now()->subHour()]);
    }
    // A recurring task sets a weekly goal of which nothing was completed by Wednesday.
    Task::create([
        'household_id' => $this->household->id,
        'created_by' => $this->user->id,
        'name' => 'Weekly cleaning',
        'duration_minutes' => 60,
        'difficulty' => 'hard',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
    ]);

    $this->getJson("/api/households/{$this->household->id}/house")
        ->assertOk()
        // 100 − 8 × (3 + 3) mess − 15 for the pace
        ->assertJsonPath('mood.score', 37)
        ->assertJsonPath('mood.band', 'grumpy')
        ->assertJsonPath('mood.behind_pace', true);
});

it('does not count the pace against a household that earned its share', function () {
    Task::create([
        'household_id' => $this->household->id,
        'created_by' => $this->user->id,
        'name' => 'Weekly cleaning',
        'duration_minutes' => 60,
        'difficulty' => 'hard',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'week',
    ]);
    TaskInstance::query()->update(['status' => TaskInstanceStatusEnum::ACCEPTED, 'completed_at' => now()]);
    PointTransaction::create([
        'household_id' => $this->household->id,
        'user_id' => $this->user->id,
        'amount' => 1000,
        'balance_after' => 1000,
        'type' => PointTransactionType::TASK_COMPLETION,
    ]);

    $this->getJson("/api/households/{$this->household->id}/house")
        ->assertOk()
        ->assertJsonPath('mood.behind_pace', false)
        ->assertJsonPath('mood.score', 100);
});

it('lists every member with the chosen or the default character', function () {
    $child = houseUser('Kid', CharacterEnum::PENGUIN);
    $this->household->users()->attach($child->id, ['role' => RoleEnum::CHILD]);

    $this->getJson("/api/households/{$this->household->id}/house")
        ->assertOk()
        ->assertJsonCount(2, 'members')
        ->assertJsonPath('members.0.user_id', $this->user->id)
        ->assertJsonPath('members.0.character', CharacterEnum::defaultFor($this->user->id)->value)
        ->assertJsonPath('members.0.is_me', true)
        ->assertJsonPath('members.1.role', 'child')
        ->assertJsonPath('members.1.character', 'penguin');
});

it('lists the recent completions with their category', function () {
    $kitchen = houseCategory('utensils');
    $instance = houseInstance($this->household, $kitchen, ['status' => TaskInstanceStatusEnum::ACCEPTED, 'completed_at' => now()]);
    $transaction = PointTransaction::create([
        'household_id' => $this->household->id,
        'user_id' => $this->user->id,
        'amount' => 5,
        'balance_after' => 5,
        'type' => PointTransactionType::TASK_COMPLETION,
        'task_instance_id' => $instance->id,
    ]);

    $this->getJson("/api/households/{$this->household->id}/house")
        ->assertOk()
        ->assertJsonPath('recent_completions.0.id', $transaction->id)
        ->assertJsonPath('recent_completions.0.user_id', $this->user->id)
        ->assertJsonPath('recent_completions.0.category_icon', 'utensils');
});

it('does not let a non member see the house', function () {
    Sanctum::actingAs(houseUser());

    $this->getJson("/api/households/{$this->household->id}/house")->assertForbidden();
});

it('lets the user choose and reset the character', function () {
    $this->putJson('/api/user/me', ['character' => 'fox'])
        ->assertOk()
        ->assertJsonPath('user.character', 'fox');
    expect($this->user->fresh()->character)->toBe(CharacterEnum::FOX);

    $this->putJson('/api/user/me', ['character' => null])->assertOk();
    expect($this->user->fresh()->character)->toBeNull();

    $this->putJson('/api/user/me', ['character' => 'fish'])->assertUnprocessable();
});
