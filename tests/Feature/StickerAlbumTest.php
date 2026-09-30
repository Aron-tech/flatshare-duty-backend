<?php

use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskSticker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function stickerUser(): User
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

function stickerTask(Household $household, array $attributes = []): Task
{
    return Task::create([
        'household_id' => $household->id,
        'created_by' => $household->created_by,
        'name' => 'Task '.uniqid(),
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        ...$attributes,
    ]);
}

/**
 * Completed claims of the user on the task, one a day, the newest one yesterday.
 */
function completeTaskTimes(Task $task, User $user, int $times): void
{
    foreach (range($times, 1) as $days_ago) {
        $task_instance = $task->taskInstances()->create([
            'household_id' => $task->household_id,
            'status' => TaskInstanceStatusEnum::ACCEPTED,
            'completed_at' => now()->subDays($days_ago),
        ]);
        $task_instance->taskInstanceUsers()->create(['user_id' => $user->id, 'completed_at' => now()->subDays($days_ago)]);
    }
}

beforeEach(function () {
    $this->user = stickerUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::ADMIN, 'points_balance' => 0]);
    Sanctum::actingAs($this->user);
});

it('lists a page with every milestone slot for every task of the household', function () {
    stickerTask($this->household, ['name' => 'Dishes']);
    stickerTask($this->household, ['name' => 'Bins']);

    $this->getJson("/api/households/{$this->household->id}/sticker-album")
        ->assertOk()
        ->assertJsonPath('milestones', TaskSticker::MILESTONES)
        ->assertJsonPath('collected', 0)
        ->assertJsonPath('total', 8)
        ->assertJsonCount(2, 'pages')
        ->assertJsonPath('pages.0.task_name', 'Bins')
        ->assertJsonPath('pages.0.next_milestone', 10)
        ->assertJsonCount(4, 'pages.0.stickers')
        ->assertJsonPath('pages.0.stickers.0.unlocked_at', null);
});

it('unlocks the stickers of the completions made before at the time the milestone was reached', function () {
    $task = stickerTask($this->household);
    completeTaskTimes($task, $this->user, 26);

    $response = $this->getJson("/api/households/{$this->household->id}/sticker-album")
        ->assertOk()
        ->assertJsonPath('collected', 2)
        ->assertJsonPath('new_count', 2)
        ->assertJsonPath('pages.0.completions', 26)
        ->assertJsonPath('pages.0.next_milestone', 50)
        ->assertJsonPath('pages.0.stickers.0.is_new', true)
        ->assertJsonPath('pages.0.stickers.2.unlocked_at', null);

    $tenth_completion = now()->subDays(26 - 9)->startOfSecond();
    expect(TaskSticker::query()->where('milestone', 10)->sole()->unlocked_at->equalTo($tenth_completion))->toBeTrue()
        ->and($response->json('pages.0.stickers.1.unlocked_at'))->not->toBeNull();
});

it('does not count the completions of other members or the released claims', function () {
    $task = stickerTask($this->household);
    $other = stickerUser();
    $this->household->users()->attach($other->id, ['role' => RoleEnum::USER, 'points_balance' => 0]);
    completeTaskTimes($task, $other, 10);
    completeTaskTimes($task, $this->user, 9);
    $task->taskInstances()->latest('id')->first()->taskInstanceUsers()->first()->delete();

    $this->getJson("/api/households/{$this->household->id}/sticker-album")
        ->assertJsonPath('collected', 0)
        ->assertJsonPath('pages.0.completions', 8);
});

it('returns the sticker unlocked by the completion reaching a milestone', function () {
    $task = stickerTask($this->household, ['name' => 'Windows']);
    completeTaskTimes($task, $this->user, 8);

    $this->postJson("/api/households/{$this->household->id}/tasks/{$task->id}/log")
        ->assertOk()
        ->assertJsonPath('new_sticker', null);

    $this->travel(10)->minutes();

    $this->postJson("/api/households/{$this->household->id}/tasks/{$task->id}/log")
        ->assertOk()
        ->assertJsonPath('new_sticker.task_id', $task->id)
        ->assertJsonPath('new_sticker.task_name', 'Windows')
        ->assertJsonPath('new_sticker.milestone', 10);

    expect(TaskSticker::query()->where('user_id', $this->user->id)->where('task_id', $task->id)->pluck('milestone')->all())->toBe([10]);
});

it('marks the new stickers seen, optionally of one task only', function () {
    $dishes = stickerTask($this->household);
    $bins = stickerTask($this->household);
    completeTaskTimes($dishes, $this->user, 10);
    completeTaskTimes($bins, $this->user, 10);
    $this->getJson("/api/households/{$this->household->id}/sticker-album")->assertJsonPath('new_count', 2);

    $this->postJson("/api/households/{$this->household->id}/sticker-album/seen", ['task_id' => $dishes->id])
        ->assertOk()
        ->assertJsonPath('marked', 1);
    $this->getJson("/api/households/{$this->household->id}/sticker-album")->assertJsonPath('new_count', 1);

    $this->postJson("/api/households/{$this->household->id}/sticker-album/seen")->assertJsonPath('marked', 1);
    $this->getJson("/api/households/{$this->household->id}/sticker-album")->assertJsonPath('new_count', 0);
});

it('does not show the album to a non-member', function () {
    Sanctum::actingAs(stickerUser());

    $this->getJson("/api/households/{$this->household->id}/sticker-album")->assertForbidden();
    $this->postJson("/api/households/{$this->household->id}/sticker-album/seen")->assertForbidden();
});
