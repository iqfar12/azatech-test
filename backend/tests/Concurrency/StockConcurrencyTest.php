<?php

declare(strict_types=1);

use App\Domain\Order\OrderStateMachine;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Parallel;

/*
 * These tests fork real OS processes; every child has its own PostgreSQL connection,
 * exactly like N application workers serving N simultaneous HTTP requests.
 * Data is committed (DatabaseTruncation) so children can see it.
 */

beforeEach(function () {
    Queue::fake();
    $this->cashier = User::factory()->cashier()->create();
    $this->admin = User::factory()->admin()->create();
    $this->customer = Customer::factory()->create();
});

it('never oversells: 20 parallel HTTP orders for a product with stock 5 → exactly 5 succeed', function () {
    $product = Product::factory()->withStock(5, threshold: 0)->create();
    Sanctum::actingAs($this->cashier);

    $results = Parallel::run(20, function () use ($product): string {
        $response = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/orders', [
                'customer_id' => $this->customer->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ]);

        return (string) $response->status();
    });

    $counts = array_count_values($results);
    expect($counts['201'] ?? 0)->toBe(5, 'results: '.json_encode($counts))
        ->and($counts['422'] ?? 0)->toBe(15)
        ->and($product->refresh()->stock)->toBe(0)
        ->and(Order::count())->toBe(5)
        ->and(Order::query()->distinct()->count('order_number'))->toBe(5)
        ->and(StockMovement::query()->where('type', StockMovementType::OrderOut)->count())->toBe(5)
        ->and(StockMovement::query()->min('stock_after'))->toBe(0);
});

it('does not deadlock when multi-item orders list the same products in opposite order', function () {
    $a = Product::factory()->withStock(100, threshold: 0)->create();
    $b = Product::factory()->withStock(100, threshold: 0)->create();
    $c = Product::factory()->withStock(100, threshold: 0)->create();

    $results = Parallel::run(24, function (int $worker) use ($a, $b, $c): string {
        // Half the workers send [A, B, C], the other half [C, B, A].
        $lines = [['product_id' => $a->id, 'quantity' => 1], ['product_id' => $b->id, 'quantity' => 1], ['product_id' => $c->id, 'quantity' => 1]];
        if ($worker % 2 === 1) {
            $lines = array_reverse($lines);
        }

        app(OrderService::class)->create(User::query()->findOrFail($this->cashier->id), $this->customer->id, $lines);

        return 'ok';
    });

    expect(array_count_values($results))->toBe(['ok' => 24])
        ->and($a->refresh()->stock)->toBe(76)
        ->and($b->refresh()->stock)->toBe(76)
        ->and($c->refresh()->stock)->toBe(76);
});

it('restores stock exactly once when the same order is cancelled concurrently', function () {
    $product = Product::factory()->withStock(10, threshold: 0)->create();
    $order = placeOrder($this->cashier, [[$product, 4]], $this->customer);
    expect($product->refresh()->stock)->toBe(6);

    $results = Parallel::run(10, function () use ($order): string {
        try {
            app(OrderStateMachine::class)->cancel(Order::query()->findOrFail($order->id), User::query()->findOrFail($this->admin->id), 'dobel klik');

            return 'cancelled';
        } catch (App\Exceptions\InvalidOrderTransitionException) {
            return 'conflict';
        }
    });

    expect(array_count_values($results))->toBe(['cancelled' => 1, 'conflict' => 9])
        ->and($product->refresh()->stock)->toBe(10)
        ->and(StockMovement::query()->where('type', StockMovementType::CancelRestore)->count())->toBe(1);
});

it('creates a single order when the same Idempotency-Key is sent concurrently', function () {
    $product = Product::factory()->withStock(50, threshold: 0)->create();
    Sanctum::actingAs($this->cashier);
    $key = (string) Str::uuid();
    $payload = ['customer_id' => $this->customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 2]]];

    $results = Parallel::run(10, function () use ($key, $payload): string {
        $response = $this->withHeaders(['Idempotency-Key' => $key])->postJson('/api/orders', $payload);

        return $response->status().':'.$response->headers->get('Idempotent-Replayed', '-');
    });

    // Every request either created the order, replayed it, or was told to retry (still processing).
    foreach ($results as $result) {
        expect($result)->toBeIn(['201:false', '201:true', '409:-']);
    }
    expect(array_count_values($results)['201:false'] ?? 0)->toBe(1)
        ->and(Order::count())->toBe(1)
        ->and($product->refresh()->stock)->toBe(48);
});
