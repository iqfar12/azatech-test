<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Product;
use App\Services\Idempotency\IdempotencyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = actingAsRole('cashier');
    $this->product = Product::factory()->withStock(10)->create();
    $this->payload = [
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
    ];
});

it('returns the same response for a retried request without creating a second order', function () {
    $key = (string) Str::uuid();

    $first = postOrder($this->payload, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'false');
    $second = postOrder($this->payload, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and(Order::count())->toBe(1)
        ->and($this->product->refresh()->stock)->toBe(8);
});

it('treats JSON key order / formatting as the same payload', function () {
    $key = (string) Str::uuid();
    postOrder($this->payload, $key)->assertCreated();

    $reordered = ['items' => [['quantity' => 2, 'product_id' => (string) $this->product->id]], 'customer_id' => strtoupper($this->payload['customer_id'])];
    postOrder($reordered, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(Order::count())->toBe(1);
});

it('rejects the same key with a different payload with 422', function () {
    $key = (string) Str::uuid();
    postOrder($this->payload, $key)->assertCreated();

    $different = $this->payload;
    $different['items'][0]['quantity'] = 3;

    postOrder($different, $key)
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors('Idempotency-Key');

    expect(Order::count())->toBe(1)->and($this->product->refresh()->stock)->toBe(8);
});

it('returns 409 with Retry-After while the first request is still processing', function () {
    $key = (string) Str::uuid();
    IdempotencyKey::query()->create([
        'user_id' => $this->user->id,
        'scope' => 'orders.create',
        'idempotency_key' => $key,
        'request_fingerprint' => hash('sha256', json_encode([
            'customer_id' => $this->payload['customer_id'],
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ])),
        'status' => IdempotencyKey::STATUS_PROCESSING,
        'lock_token' => (string) Str::uuid(),
        'locked_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    postOrder($this->payload, $key)->assertStatus(409)->assertHeader('Retry-After', '1');

    expect(Order::count())->toBe(0);
});

it('releases the key when the request fails so the client can retry it', function () {
    $key = (string) Str::uuid();
    $tooMuch = $this->payload;
    $tooMuch['items'][0]['quantity'] = 50;

    postOrder($tooMuch, $key)->assertStatus(422);
    expect(IdempotencyKey::count())->toBe(0);

    // Same key, now valid (e.g. stock was replenished) → processed normally.
    postOrder($this->payload, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'false');
});

it('scopes keys per user', function () {
    $key = (string) Str::uuid();
    postOrder($this->payload, $key)->assertCreated();

    Sanctum::actingAs(cashier());
    postOrder($this->payload, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'false');

    expect(Order::count())->toBe(2);
});

it('expires keys after the TTL', function () {
    $key = (string) Str::uuid();
    postOrder($this->payload, $key)->assertCreated();

    Carbon::setTestNow(now()->addMinutes((int) config('shop.idempotency.ttl_minutes') + 1));
    postOrder($this->payload, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'false');
    expect(Order::count())->toBe(2);

    expect(app(IdempotencyService::class)->pruneExpired())->toBe(0); // the old row was replaced on reuse
    Carbon::setTestNow();
});

it('requires a UUID Idempotency-Key header', function (?string $key) {
    $request = $key === null
        ? $this->postJson('/api/orders', $this->payload)
        : $this->withHeaders(['Idempotency-Key' => $key])->postJson('/api/orders', $this->payload);

    $request->assertStatus(422)->assertJsonValidationErrors('Idempotency-Key');
    expect(Order::count())->toBe(0);
})->with(['missing' => null, 'not a uuid' => 'abc-123']);
