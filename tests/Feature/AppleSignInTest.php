<?php

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $apple_key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($apple_key, $this->apple_private_key);
    $details = openssl_pkey_get_details($apple_key);

    $client_key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($client_key, $client_private_key);

    config([
        'services.apple.client_id' => 'com.example.flatshare',
        'services.apple.team_id' => 'TEAM123456',
        'services.apple.key_id' => 'KEY1234567',
        'services.apple.private_key' => $client_private_key,
    ]);

    Http::fake([
        'appleid.apple.com/auth/keys' => Http::response(['keys' => [[
            'kty' => 'RSA',
            'kid' => 'apple-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ]]]),
        'appleid.apple.com/auth/token' => Http::response(['refresh_token' => 'apple-refresh-token']),
    ]);
});

/**
 * @param  array<string, mixed>  $claims
 */
function appleIdentityToken(string $private_key, array $claims = []): string
{
    return JWT::encode([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'com.example.flatshare',
        'sub' => '001234.apple-user',
        'email' => 'apple@example.com',
        'iat' => now()->timestamp,
        'exp' => now()->addMinutes(10)->timestamp,
        ...$claims,
    ], $private_key, 'RS256', 'apple-key');
}

it('creates the user from a valid identity token and keeps the refresh token for the revocation', function () {
    $response = $this->postJson('/api/auth/apple', [
        'identity_token' => appleIdentityToken($this->apple_private_key),
        'authorization_code' => 'code',
        'first_name' => 'Anna',
        'last_name' => 'Kiss',
        'language' => 'en',
    ])->assertOk();

    $user = User::query()->where('apple_id', '001234.apple-user')->firstOrFail();
    expect($response->json('token'))->not->toBeEmpty()
        ->and($response->json('user.email'))->toBe('apple@example.com')
        ->and($response->json('user'))->not->toHaveKey('apple_refresh_token')
        ->and($user->first_name)->toBe('Anna')
        ->and($user->workos_id)->toBeNull()
        ->and($user->apple_refresh_token)->toBe('apple-refresh-token');

    Http::assertSent(fn ($request) => $request->url() === 'https://appleid.apple.com/auth/token'
        && $request['client_id'] === 'com.example.flatshare'
        && $request['code'] === 'code');
});

it('links the Apple id to the existing user with the same e-mail and keeps the name', function () {
    $user_id = DB::table('users')->insertGetId([
        'workos_id' => 'user_workos',
        'first_name' => 'Existing',
        'last_name' => 'User',
        'email' => 'apple@example.com',
        'avatar' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/auth/apple', [
        'identity_token' => appleIdentityToken($this->apple_private_key),
        'authorization_code' => 'code',
    ])->assertOk();

    $user = User::findOrFail($user_id);
    expect($user->apple_id)->toBe('001234.apple-user')
        ->and($user->first_name)->toBe('Existing')
        ->and(User::count())->toBe(1);
});

it('refuses an identity token issued for another app', function () {
    $this->postJson('/api/auth/apple', [
        'identity_token' => appleIdentityToken($this->apple_private_key, ['aud' => 'com.other.app']),
        'authorization_code' => 'code',
    ])->assertUnauthorized();

    expect(User::count())->toBe(0);
});

it('refuses an identity token not signed by Apple', function () {
    openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]), $forged_key);

    $this->postJson('/api/auth/apple', [
        'identity_token' => appleIdentityToken($forged_key),
        'authorization_code' => 'code',
    ])->assertUnauthorized();

    expect(User::count())->toBe(0);
});
