<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
 * Feature tests: real PostgreSQL, each test wrapped in a transaction (RefreshDatabase).
 * Concurrency tests: real PostgreSQL with committed data (DatabaseTruncation), because
 * forked worker processes use their own connections and cannot see uncommitted rows.
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Concurrency');

function admin(): User
{
    return User::factory()->admin()->create();
}

function cashier(): User
{
    return User::factory()->cashier()->create();
}

function actingAsRole(string $role): User
{
    $user = $role === 'admin' ? admin() : cashier();
    Sanctum::actingAs($user);

    return $user;
}

/**
 * Creates an order through the real service (stock reserved, ledger written).
 *
 * @param  list<array{0: Product, 1: int}>  $lines  [product, quantity]
 */
function placeOrder(User $actor, array $lines, ?Customer $customer = null): Order
{
    $customer ??= Customer::factory()->create();

    return app(OrderService::class)->create(
        $actor,
        $customer->id,
        array_map(fn (array $line): array => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function postOrder(array $payload, ?string $key = null): TestResponse
{
    return test()->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])
        ->postJson('/api/orders', $payload);
}
