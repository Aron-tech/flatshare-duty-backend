<?php

use App\Enums\RoleEnum;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function authorizationUser(): User
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
    $this->admin = authorizationUser();
    $this->member = authorizationUser();
    $this->outsider = authorizationUser();
    $this->household = Household::create(['name' => 'Home', 'join_code' => '0000000001', 'created_by' => $this->admin->id]);
    $this->household->users()->attach($this->admin->id, ['role' => RoleEnum::ADMIN]);
    $this->household->users()->attach($this->member->id, ['role' => RoleEnum::USER]);
    $this->member_household_user = HouseholdUser::where('user_id', $this->member->id)->sole();
});

it('gives the qr code with the join code only to members', function () {
    Sanctum::actingAs($this->outsider);
    $this->get("/api/households/{$this->household->id}/qrcode")->assertForbidden();

    Sanctum::actingAs($this->member);
    $this->get("/api/households/{$this->household->id}/qrcode")->assertOk();
});

it('does not let an outsider or a non-admin member remove a member', function () {
    $other_member = authorizationUser();
    $this->household->users()->attach($other_member->id, ['role' => RoleEnum::USER]);

    Sanctum::actingAs($this->outsider);
    $this->deleteJson("/api/household-users/{$this->member_household_user->id}")->assertForbidden();

    Sanctum::actingAs($other_member);
    $this->deleteJson("/api/household-users/{$this->member_household_user->id}")->assertForbidden();

    expect($this->member_household_user->fresh())->not->toBeNull();
});

it('lets a member leave and an admin remove a member', function () {
    Sanctum::actingAs($this->member);
    $this->deleteJson("/api/household-users/{$this->member_household_user->id}")->assertOk();

    $this->household->users()->attach($this->member->id, ['role' => RoleEnum::USER]);
    $household_user = HouseholdUser::where('user_id', $this->member->id)->sole();

    Sanctum::actingAs($this->admin);
    $this->deleteJson("/api/household-users/{$household_user->id}")->assertOk();

    expect($household_user->fresh())->toBeNull();
});

it('registers the scheduled recurring task generation command', function () {
    $task = Task::create([
        'household_id' => $this->household->id,
        'created_by' => $this->admin->id,
        'name' => 'Dishes',
        'duration_minutes' => 10,
        'difficulty' => 'easy',
        'is_recurring' => true,
        'recurrence_interval' => 1,
        'recurrence_unit' => 'day',
    ]);
    $task->taskInstances()->update(['created_at' => now()->subDays(2), 'status' => 'accepted', 'completed_at' => now()->subDay()]);

    expect(Artisan::call('tasks:generate-instances'))->toBe(0)
        ->and($task->taskInstances()->count())->toBe(2);
});
