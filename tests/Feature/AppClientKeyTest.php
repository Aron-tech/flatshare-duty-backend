<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.client_keys' => ['old-key', 'new-key']]);

    Sanctum::actingAs(User::findOrFail(DB::table('users')->insertGetId([
        'workos_id' => 'user_'.uniqid(),
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => uniqid().'@example.com',
        'avatar' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ])));
});

it('refuses requests without a known app key', function (?string $key) {
    $this->withHeaders(array_filter(['X-App-Key' => $key]))->getJson('/api/user/me')->assertForbidden();
})->with([null, 'wrong-key']);

it('answers every configured app key, so the keys can be rotated', function (string $key) {
    $this->withHeader('X-App-Key', $key)->getJson('/api/user/me')->assertOk();
})->with(['old-key', 'new-key']);

it('refuses everything in production when no key is configured', function () {
    config(['app.client_keys' => []]);
    app()->detectEnvironment(fn (): string => 'production');

    $this->getJson('/api/user/me')->assertForbidden();
});

it('keeps the calendar feed open for the calendar apps', function () {
    $this->get('/api/calendar/unknowntoken.ics')->assertNotFound();
});
