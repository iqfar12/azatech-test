<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->user = User::factory()->admin()->create(['email' => 'admin@azatech.id', 'password' => 'secret-123']);
});

it('logs in with valid credentials and returns an expiring bearer token', function () {
    $response = $this->postJson('/api/auth/login', ['email' => 'admin@azatech.id', 'password' => 'secret-123'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.role', 'admin');

    $token = PersonalAccessToken::findToken($response->json('data.access_token'));
    expect($token)->not->toBeNull()
        ->and($token->expires_at)->not->toBeNull()
        ->and($token->expires_at->isFuture())->toBeTrue();
});

it('rejects wrong credentials with 401 without revealing which part was wrong', function (string $email, string $password) {
    $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
        ->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Email atau password salah');
})->with([
    'wrong password' => ['admin@azatech.id', 'nope'],
    'unknown e-mail' => ['ghost@azatech.id', 'secret-123'],
]);

it('validates the login payload', function () {
    $this->postJson('/api/auth/login', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('returns the current user on /me', function () {
    $token = $this->user->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'admin@azatech.id')
        ->assertJsonPath('data.role', 'admin');
});

it('revokes the token on logout so it cannot be reused', function () {
    $token = $this->user->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

    expect(PersonalAccessToken::count())->toBe(0);

    // Fresh application state so the guard does not reuse the user resolved in the previous request.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
});

it('rejects expired tokens', function () {
    $token = $this->user->createToken('test', ['*'], now()->addMinutes(5))->plainTextToken;

    Carbon::setTestNow(now()->addMinutes(6));

    $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);

    Carbon::setTestNow();
});

it('requires authentication for protected endpoints', function () {
    $this->getJson('/api/products')
        ->assertStatus(401)
        ->assertJsonPath('success', false);
});

it('rate limits repeated login attempts', function () {
    config(['shop.rate_limits.login_per_minute' => 3]);

    foreach (range(1, 3) as $_) {
        $this->postJson('/api/auth/login', ['email' => 'admin@azatech.id', 'password' => 'wrong'])->assertStatus(401);
    }

    $this->postJson('/api/auth/login', ['email' => 'admin@azatech.id', 'password' => 'wrong'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});
