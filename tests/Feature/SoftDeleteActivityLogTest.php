<?php

use App\Enums\RoleEnum;
use App\Enums\TaskInstanceStatusEnum;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function softDeleteUser(): User
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
    $this->user = softDeleteUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->user->id]);
    $this->household->users()->attach($this->user->id, ['role' => RoleEnum::ADMIN]);
    Sanctum::actingAs($this->user);
});

it('soft deletes a task but keeps its completed instances readable', function () {
    $task = Task::create(['household_id' => $this->household->id, 'name' => 'Dishes', 'duration_minutes' => 10, 'difficulty' => 'easy']);
    $completed = $task->taskInstances()->sole();
    $completed->update(['status' => TaskInstanceStatusEnum::ACCEPTED, 'completed_at' => now()]);
    $pending = $task->taskInstances()->create(['household_id' => $this->household->id, 'status' => TaskInstanceStatusEnum::PENDING]);

    $this->deleteJson("/api/households/{$this->household->id}/tasks/{$task->id}")->assertOk();

    expect(Task::count())->toBe(0)
        ->and($task->fresh()->trashed())->toBeTrue()
        ->and($pending->fresh()->trashed())->toBeTrue()
        ->and(TaskInstance::pluck('id')->all())->toBe([$completed->id])
        ->and($completed->fresh()->task->name)->toBe('Dishes');

    $this->postJson("/api/households/{$this->household->id}/tasks", [
        'name' => 'Dishes',
        'duration_minutes' => 10,
        'difficulty' => 'easy',
    ])->assertOk();
});

it('soft deletes a household and logs the removal of its members', function () {
    $this->deleteJson("/api/households/{$this->household->id}")->assertOk();

    expect(Household::count())->toBe(0)
        ->and($this->household->fresh()->trashed())->toBeTrue()
        ->and(HouseholdUser::count())->toBe(0)
        ->and(Activity::where('subject_type', (new HouseholdUser)->getMorphClass())->where('event', 'deleted')->exists())->toBeTrue()
        ->and(Activity::where('subject_type', (new Household)->getMorphClass())->where('event', 'deleted')->sole()->causer_id)->toBe($this->user->id);
});

it('logs membership and role changes but not the points balance', function () {
    $member = softDeleteUser();
    $member->households()->attach($this->household->id, ['role' => RoleEnum::USER]);
    $household_user = HouseholdUser::where('user_id', $member->id)->sole();

    $household_user->update(['points_balance' => 50]);
    $household_user->update(['role' => RoleEnum::CHILD]);

    $activities = Activity::where('subject_type', $household_user->getMorphClass())->where('subject_id', $household_user->id)->orderBy('id')->get();

    expect($activities->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($activities->last()->attribute_changes['attributes'])->toBe(['role' => RoleEnum::CHILD->value]);
});

it('soft deletes a reward and keeps it on its redemptions', function () {
    $reward = Reward::create(['household_id' => $this->household->id, 'user_id' => $this->user->id, 'name' => 'Cinema', 'points_cost' => 100]);
    $redemption = RewardRedemption::create(['household_id' => $this->household->id, 'reward_id' => $reward->id, 'user_id' => $this->user->id, 'points_spent' => 100]);

    $this->deleteJson("/api/households/{$this->household->id}/rewards/{$reward->id}")->assertOk();

    expect(Reward::count())->toBe(0)
        ->and($redemption->fresh()->reward->name)->toBe('Cinema');
});
