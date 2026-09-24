<?php

use App\Actions\PushToken\SendPushNotificationAction;
use App\Jobs\SendExpoPushNotificationsJob;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function makeUser(): User
{
    // A User modell `language` cast-ja jelenleg hibás, ezért közvetlenül szúrjuk be.
    $id = DB::table('users')->insertGetId([
        'workos_id' => 'user_'.uniqid(),
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => uniqid().'@example.com',
        'avatar' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::findOrFail($id);
}

it('stores a push token and reassigns it to the latest user', function () {
    [$first, $second] = [makeUser(), makeUser()];
    $payload = ['token' => 'ExponentPushToken[abc]', 'platform' => 'ios'];

    Sanctum::actingAs($first);
    $this->postJson('/api/push-tokens', $payload)->assertOk();

    Sanctum::actingAs($second);
    $this->postJson('/api/push-tokens', $payload)->assertOk();

    expect(PushToken::count())->toBe(1)
        ->and(PushToken::first()->user_id)->toBe($second->id);
});

it('rejects invalid tokens', function () {
    Sanctum::actingAs(makeUser());

    $this->postJson('/api/push-tokens', ['token' => 'nope', 'platform' => 'ios'])->assertUnprocessable();
    $this->postJson('/api/push-tokens', ['token' => 'ExpoPushToken[x]', 'platform' => 'web'])->assertUnprocessable();
});

it('deletes only the own token', function () {
    [$owner, $other] = [makeUser(), makeUser()];
    $owner->pushTokens()->create(['token' => 'ExpoPushToken[mine]', 'platform' => 'android']);

    Sanctum::actingAs($other);
    $this->deleteJson('/api/push-tokens', ['token' => 'ExpoPushToken[mine]'])->assertOk();
    expect(PushToken::count())->toBe(1);

    Sanctum::actingAs($owner);
    $this->deleteJson('/api/push-tokens', ['token' => 'ExpoPushToken[mine]'])->assertOk();
    expect(PushToken::count())->toBe(0);
});

it('requires authentication', function () {
    $this->postJson('/api/push-tokens', [])->assertUnauthorized();
});

it('queues a notification for all tokens of the given users', function () {
    Queue::fake();
    $user = makeUser();
    $user->pushTokens()->create(['token' => 'ExpoPushToken[a]', 'platform' => 'ios']);
    $user->pushTokens()->create(['token' => 'ExpoPushToken[b]', 'platform' => 'android']);

    SendPushNotificationAction::run([$user->id], 'Cím', 'Szöveg', ['route' => '/']);

    Queue::assertPushed(SendExpoPushNotificationsJob::class, fn ($job) => count($job->tokens) === 2);
});

it('does not queue anything without tokens', function () {
    Queue::fake();

    SendPushNotificationAction::run([makeUser()->id], 'Cím', 'Szöveg');

    Queue::assertNothingPushed();
});

it('sends to expo and removes unregistered tokens', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [
        ['status' => 'ok', 'id' => '1'],
        ['status' => 'error', 'message' => 'x', 'details' => ['error' => 'DeviceNotRegistered']],
    ]])]);
    $user = makeUser();
    $user->pushTokens()->create(['token' => 'ExpoPushToken[good]', 'platform' => 'ios']);
    $user->pushTokens()->create(['token' => 'ExpoPushToken[dead]', 'platform' => 'ios']);

    SendPushNotificationAction::run([$user->id], 'Cím', 'Szöveg');

    Http::assertSentCount(1);
    expect(PushToken::pluck('token')->all())->toBe(['ExpoPushToken[good]']);
});
