<?php

use App\Enums\RoleEnum;
use App\Enums\TaskUserWeightEnum;
use App\Models\Category;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\TaskUserWeight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function householdTaskUser(): User
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

function householdTask(Household $household): Task
{
    return Task::create([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => 'Task',
        'duration_minutes' => 10,
        'difficulty' => 'easy',
    ]);
}

function householdTaskTemplate(): TaskTemplate
{
    $category = Category::create(['name' => ['hu' => 'Konyha', 'en' => 'Kitchen'], 'icon' => 'kitchen', 'color' => '#D87758']);

    return TaskTemplate::create([
        'name' => ['hu' => 'Mosogatás', 'en' => 'Dishes'],
        'description' => ['hu' => 'Leírás', 'en' => 'Description'],
        'category_id' => $category->id,
        'icon' => 'sink',
        'duration_minutes' => 20,
        'difficulty' => 'medium',
    ]);
}

beforeEach(function () {
    $this->user = householdTaskUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::ADMIN]);
    Sanctum::actingAs($this->user);
});

it('stores a task with calculated base points', function () {
    $this->postJson("/api/households/{$this->household->id}/tasks", [
        'name' => 'Vacuum',
        'duration_minutes' => 20,
        'difficulty' => 'hard',
    ])->assertOk();

    expect(Task::sole()->base_points)->toBe(30);
});

it('rejects an unknown difficulty', function () {
    $this->postJson("/api/households/{$this->household->id}/tasks", [
        'name' => 'Vacuum',
        'duration_minutes' => 20,
        'difficulty' => 'impossible',
    ])->assertUnprocessable()->assertJsonValidationErrors('difficulty');
});

it('rejects a task whose name already exists in the household', function () {
    householdTask($this->household);

    $this->postJson("/api/households/{$this->household->id}/tasks", [
        'name' => ' task ',
        'duration_minutes' => 20,
        'difficulty' => 'hard',
    ])->assertUnprocessable()->assertJsonValidationErrors('name');

    expect(Task::count())->toBe(1);
});

it('allows the same task name in another household', function () {
    householdTask(Household::create(['name' => 'Other', 'join_code' => '0000000002', 'created_by' => $this->user->id]));

    $this->postJson("/api/households/{$this->household->id}/tasks", [
        'name' => 'Task',
        'duration_minutes' => 20,
        'difficulty' => 'hard',
    ])->assertOk();

    expect(Task::count())->toBe(2);
});

it('rejects adding the same template twice', function () {
    $template = householdTaskTemplate();
    $url = "/api/households/{$this->household->id}/tasks/{$template->id}";

    $this->postJson($url)->assertOk();
    $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('task_template');

    expect(Task::count())->toBe(1);
});

it('rejects a template whose name matches an existing custom task', function () {
    $template = householdTaskTemplate();
    $this->postJson("/api/households/{$this->household->id}/tasks", [
        'name' => $template->getTranslation('name', app()->getLocale()),
        'duration_minutes' => 20,
        'difficulty' => 'hard',
    ])->assertOk();

    $this->postJson("/api/households/{$this->household->id}/tasks/{$template->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('task_template');
});

it('stores a task from a template', function () {
    $template = householdTaskTemplate();

    $this->postJson("/api/households/{$this->household->id}/tasks/{$template->id}", [
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'day',
    ])->assertOk();

    $task = Task::sole();
    expect($task->task_template_id)->toBe($template->id)
        ->and($task->created_by)->toBe($this->user->id)
        ->and($task->category_id)->toBe($template->category_id)
        ->and($task->base_points)->toBe(24)
        ->and($task->is_recurring)->toBeTrue();
});

it('opens a pending task instance for a new template task', function () {
    $template = householdTaskTemplate();

    $this->postJson("/api/households/{$this->household->id}/tasks/{$template->id}")->assertOk();

    $instance = Task::sole()->taskInstances()->sole();
    expect($instance->household_id)->toBe($this->household->id)
        ->and($instance->status->value)->toBe('pending');
});

it('requires the recurrence when a template task is recurring', function () {
    $template = householdTaskTemplate();

    $this->postJson("/api/households/{$this->household->id}/tasks/{$template->id}", ['is_recurring' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence_interval', 'recurrence_unit']);
});

it('stores the user weight of a task', function () {
    $task = householdTask($this->household);
    $url = "/api/households/{$this->household->id}/tasks/{$task->id}/user-weight";

    $this->postJson($url, ['weight' => 'love'])->assertOk();
    $this->postJson($url, ['weight' => 'hate'])->assertOk();
    $this->postJson($url, ['weight' => 'meh'])->assertUnprocessable();

    expect(TaskUserWeight::sole()->weight)->toBe(TaskUserWeightEnum::HATE);
});

it('does not store a weight for another household task', function () {
    $other_household = Household::create(['name' => 'Other', 'join_code' => '0000000002', 'created_by' => $this->user->id]);
    $task = householdTask($other_household);

    $this->postJson("/api/households/{$this->household->id}/tasks/{$task->id}/user-weight", ['weight' => 'love'])
        ->assertNotFound();
});

it('deletes a task of the household only', function () {
    $task = householdTask($this->household);
    $other_task = householdTask(Household::create(['name' => 'Other', 'join_code' => '0000000002', 'created_by' => $this->user->id]));

    $this->deleteJson("/api/households/{$this->household->id}/tasks/{$other_task->id}")->assertNotFound();
    $this->deleteJson("/api/households/{$this->household->id}/tasks/{$task->id}")->assertOk();

    expect(Task::pluck('id')->all())->toBe([$other_task->id]);
});

it('lists the rewards for members only', function () {
    $this->getJson("/api/households/{$this->household->id}/rewards")->assertOk()->assertJsonPath('rewards', []);

    Sanctum::actingAs(householdTaskUser());
    $this->getJson("/api/households/{$this->household->id}/rewards")->assertForbidden();
});

it('stores a task with its template and max user', function () {
    $task_template = householdTaskTemplate();

    $this->postJson("/api/households/{$this->household->id}/tasks", [
        'task_template_id' => $task_template->id,
        'name' => 'Mosogatás',
        'duration_minutes' => 20,
        'difficulty' => 'medium',
        'icon' => 'sink',
        'max_user' => 3,
    ])->assertOk();

    $task = Task::sole();
    expect($task->task_template_id)->toBe($task_template->id)
        ->and($task->max_user)->toBe(3);
});

it('returns the validation errors per field', function () {
    $this->postJson("/api/households/{$this->household->id}/tasks", ['name' => 'ab', 'max_user' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'duration_minutes', 'difficulty', 'max_user']);
});

it('lists the household tasks for any member with the own weight and the common price', function () {
    $task = householdTask($this->household);
    $member = householdTaskUser();
    $this->household->users()->attach($member->id, ['role' => RoleEnum::CHILD]);
    TaskUserWeight::create(['household_id' => $this->household->id, 'task_id' => $task->id, 'user_id' => $this->user->id, 'weight' => TaskUserWeightEnum::LOVE]);
    Sanctum::actingAs($member);

    $this->getJson("/api/households/{$this->household->id}/tasks")
        ->assertOk()
        ->assertJsonCount(0, 'tasks.'.__('app.other').'.0.user_weights')
        ->assertJsonPath('tasks.'.__('app.other').'.0.points', 9);

    Sanctum::actingAs(householdTaskUser());
    $this->getJson("/api/households/{$this->household->id}/tasks")->assertForbidden();
});

it('updates a task and recalculates its base points', function () {
    $task = householdTask($this->household);

    $this->putJson("/api/households/{$this->household->id}/tasks/{$task->id}", [
        'name' => 'Vacuum',
        'duration_minutes' => 20,
        'difficulty' => 'hard',
        'is_recurring' => true,
        'recurrence_interval' => 2,
        'recurrence_unit' => 'week',
    ])->assertOk();

    $task->refresh();
    expect($task->name)->toBe('Vacuum')
        ->and($task->base_points)->toBe(30)
        ->and($task->is_recurring)->toBeTrue()
        ->and($task->recurrence_interval)->toBe(2);
});

it('clears the recurrence when a task is no longer recurring', function () {
    $task = householdTask($this->household);
    $task->update(['is_recurring' => true, 'recurrence_interval' => 1, 'recurrence_unit' => 'day']);

    $this->putJson("/api/households/{$this->household->id}/tasks/{$task->id}", ['is_recurring' => false])->assertOk();

    expect($task->refresh()->recurrence_interval)->toBeNull()
        ->and($task->recurrence_unit)->toBeNull();
});

it('rejects renaming a task to an existing name but keeps its own', function () {
    $task = householdTask($this->household);
    $other = Task::create([
        'household_id' => $this->household->id,
        'name' => 'Dishes',
        'duration_minutes' => 10,
        'difficulty' => 'easy',
    ]);

    $this->putJson("/api/households/{$this->household->id}/tasks/{$task->id}", ['name' => 'dishes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
    $this->putJson("/api/households/{$this->household->id}/tasks/{$other->id}", ['name' => 'Dishes'])->assertOk();
});

it('does not let a child or another household update a task', function () {
    $other_task = householdTask(Household::create(['name' => 'Other', 'join_code' => '0000000002', 'created_by' => $this->user->id]));
    $this->putJson("/api/households/{$this->household->id}/tasks/{$other_task->id}", ['name' => 'Vacuum'])->assertNotFound();

    $task = householdTask($this->household);
    $child = householdTaskUser();
    $this->household->users()->attach($child->id, ['role' => RoleEnum::CHILD]);
    Sanctum::actingAs($child);

    $this->putJson("/api/households/{$this->household->id}/tasks/{$task->id}", ['name' => 'Vacuum'])->assertForbidden();
    expect($task->refresh()->name)->toBe('Task');
});

it('opens a new unclaimed task instance of a non-recurring task for any member', function () {
    $task = householdTask($this->household);
    $child = householdTaskUser();
    $this->household->users()->attach($child->id, ['role' => RoleEnum::CHILD]);
    Sanctum::actingAs($child);

    $this->postJson("/api/households/{$this->household->id}/tasks/{$task->id}/open")->assertOk();

    $instances = $task->taskInstances()->get();
    expect($instances)->toHaveCount(2)
        ->and($instances->every(fn ($instance) => $instance->status->value === 'pending' && ! $instance->completed_at))->toBeTrue()
        ->and($instances->every(fn ($instance) => $instance->taskInstanceUsers()->doesntExist()))->toBeTrue();
});

it('does not open a task instance of a recurring task', function () {
    $task = householdTask($this->household);
    $task->update(['is_recurring' => true, 'recurrence_interval' => 1, 'recurrence_unit' => 'day']);

    $this->postJson("/api/households/{$this->household->id}/tasks/{$task->id}/open")->assertForbidden();

    expect($task->taskInstances()->count())->toBe(1);
});
