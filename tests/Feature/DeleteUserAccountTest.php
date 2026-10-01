<?php

use App\Enums\RoleEnum;
use App\Models\Household;
use App\Models\HouseholdMemberDeparture;
use App\Models\Task;
use App\Models\User;
use App\Services\Apple\AppleSignInService;
use App\Services\WorkOS\WorkOSService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function accountDeletionUser(): User
{
    return User::findOrFail(DB::table('users')->insertGetId([
        'workos_id' => 'user_'.uniqid(),
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => uniqid().'@example.com',
        'avatar' => 'https://example.com/avatar.png',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
}

function accountDeletionHousehold(User $owner, string $join_code): Household
{
    $household = Household::create(['name' => 'Home', 'join_code' => $join_code, 'created_by' => $owner->id]);
    $household->users()->attach($owner->id, ['role' => RoleEnum::ADMIN]);

    return $household;
}

beforeEach(function () {
    $this->user = accountDeletionUser();
    $this->other = accountDeletionUser();
    Sanctum::actingAs($this->user);
});

function expectWorkOsUserDeleted(string $work_os_id): void
{
    test()->mock(WorkOSService::class, fn (MockInterface $mock) => $mock->shouldReceive('deleteUser')->with($work_os_id)->once());
}

it('anonymizes the user and removes the tokens', function () {
    $this->user->pushTokens()->create(['token' => 'ExpoPushToken[a]', 'platform' => 'ios']);
    $this->user->createToken('mobile-app');
    $work_os_id = $this->user->workos_id;
    expectWorkOsUserDeleted($work_os_id);

    $this->deleteJson('/api/user/me')->assertOk();

    $user = $this->user->fresh();
    expect($user->isAnonymized())->toBeTrue()
        ->and($user->email)->not->toContain('example.com')
        ->and($user->workos_id)->not->toBe($work_os_id)
        ->and($user->first_name)->toBe('')
        ->and($user->avatar)->toBe('')
        ->and($user->name)->toBe(__('app.deleted_user'))
        ->and($user->pushTokens()->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0);
});

it('deletes the households created by the user for every member', function () {
    $household = accountDeletionHousehold($this->user, '0000000001');
    $household->users()->attach($this->other->id, ['role' => RoleEnum::USER]);
    expectWorkOsUserDeleted($this->user->workos_id);

    $this->deleteJson('/api/user/me')->assertOk();

    expect(Household::find($household->id))->toBeNull()
        ->and($this->other->households()->count())->toBe(0);
});

it('leaves the households of others, which keep existing', function () {
    $household = accountDeletionHousehold($this->other, '0000000002');
    $household->users()->attach($this->user->id, ['role' => RoleEnum::USER]);
    // The admins are asked about the tasks created by the former member.
    Task::create(['household_id' => $household->id, 'created_by' => $this->user->id, 'name' => 'Task', 'duration_minutes' => 10, 'difficulty' => 'easy']);
    expectWorkOsUserDeleted($this->user->workos_id);

    $this->deleteJson('/api/user/me')->assertOk();

    expect(Household::find($household->id))->not->toBeNull()
        ->and($household->users()->pluck('users.id')->all())->toBe([$this->other->id])
        ->and(HouseholdMemberDeparture::where('user_id', $this->user->id)->exists())->toBeTrue();
});

it('lets the same e-mail sign up again as a new account', function () {
    $email = $this->user->email;
    expectWorkOsUserDeleted($this->user->workos_id);

    $this->deleteJson('/api/user/me')->assertOk();

    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('requires authentication', function () {
    app('auth')->forgetGuards();
    $this->mock(WorkOSService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('deleteUser'));

    $this->deleteJson('/api/user/me')->assertUnauthorized();
});

it('revokes the Sign in with Apple authorization of an Apple user', function () {
    $this->user->forceFill(['workos_id' => null, 'apple_id' => 'apple-sub', 'apple_refresh_token' => 'refresh-token'])->save();
    test()->mock(WorkOSService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('deleteUser'));
    test()->mock(AppleSignInService::class, fn (MockInterface $mock) => $mock->shouldReceive('revokeRefreshToken')->with('refresh-token')->once());

    $this->deleteJson('/api/user/me')->assertOk();

    $user = $this->user->fresh();
    expect($user->apple_id)->toBeNull()
        ->and($user->apple_refresh_token)->toBeNull();
});
