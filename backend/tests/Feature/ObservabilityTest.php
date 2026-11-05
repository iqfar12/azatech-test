<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('reports healthy when PostgreSQL and Redis respond', function () {
    // Redis is a real dependency in CI / docker; outside of it the check reports down (503).
    $response = $this->getJson('/api/health');

    expect($response->status())->toBeIn([200, 503]);
    $response->assertJsonStructure(['success', 'message', 'data' => ['status', 'checks' => ['database' => ['ok', 'latency_ms'], 'redis' => ['ok', 'latency_ms']]]])
        ->assertJsonPath('data.checks.database.ok', true);
});

it('generates an X-Request-Id when none is sent', function () {
    $id = $this->getJson('/api/health')->headers->get('X-Request-Id');

    expect(Str::isUuid((string) $id))->toBeTrue();
});

it('propagates a well-formed incoming X-Request-Id', function () {
    $this->withHeaders(['X-Request-Id' => 'frontend-abc-12345'])
        ->getJson('/api/health')
        ->assertHeader('X-Request-Id', 'frontend-abc-12345');
});

it('replaces a malicious X-Request-Id', function () {
    $id = $this->withHeaders(['X-Request-Id' => "bad\nheader injection"])
        ->getJson('/api/health')
        ->headers->get('X-Request-Id');

    expect($id)->not->toContain('injection');
});

it('hides exception details on 500 when debug is off', function () {
    config(['app.debug' => false]);
    Route::middleware('api')->get('/api/_boom', fn () => throw new RuntimeException('secret db password in message'));

    $response = $this->getJson('/api/_boom')->assertStatus(500);

    expect($response->getContent())->not->toContain('secret')
        ->and($response->getContent())->not->toContain('RuntimeException')
        ->and($response->getContent())->not->toContain('.php');
    $response->assertJsonPath('success', false)->assertJsonStructure(['request_id']);
});

it('answers CORS preflight only for allowed origins', function () {
    $allowed = $this->call('OPTIONS', '/api/products', [], [], [], [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);
    expect($allowed->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:5173');

    $denied = $this->call('OPTIONS', '/api/products', [], [], [], [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);
    expect($denied->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});
