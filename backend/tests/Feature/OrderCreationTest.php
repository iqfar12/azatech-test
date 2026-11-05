<?php

declare(strict_types=1);

use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->cashier = actingAsRole('cashier');
    $this->customer = Customer::factory()->create();
    $this->hydrococo = Product::factory()->withStock(10)->priced('12000.00')->create(['product_name' => 'Hydrococo 150ml']);
    $this->aqua = Product::factory()->withStock(3)->priced('4000.00')->create(['product_name' => 'Aqua 600ml']);
});

it('creates a PENDING order with server-side prices, snapshots and reserved stock', function () {
    Carbon::setTestNow('2026-10-21 03:00:00'); // 10:00 in Asia/Jakarta

    $response = postOrder([
        'customer_id' => $this->customer->id,
        'items' => [
            ['product_id' => $this->hydrococo->id, 'quantity' => 2],
            ['product_id' => $this->aqua->id, 'quantity' => 1],
        ],
    ])->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'PENDING')
        ->assertJsonPath('data.order_number', 'INV-20261021-0001')
        ->assertJsonPath('data.total_amount', '28000.00')
        ->assertJsonPath('data.items.0.unit_price', '12000.00')
        ->assertJsonPath('data.items.0.subtotal', '24000.00')
        ->assertJsonPath('data.items.1.subtotal', '4000.00');

    expect($this->hydrococo->refresh()->stock)->toBe(8)
        ->and($this->aqua->refresh()->stock)->toBe(2);

    $orderId = $response->json('data.id');
    $movements = StockMovement::query()->where('reference_type', 'order')->where('reference_id', (string) $orderId)->get();
    expect($movements)->toHaveCount(2)
        ->and($movements->every(fn ($m) => $m->type === StockMovementType::OrderOut))->toBeTrue()
        ->and($movements->firstWhere('product_id', $this->hydrococo->id)->stock_after)->toBe(8);

    // Price snapshot: changing the product price later does not change the order.
    $this->hydrococo->update(['price' => '99999.00']);
    expect(OrderItem::query()->where('order_id', $orderId)->where('product_id', $this->hydrococo->id)->value('unit_price'))->toBe('12000.00');

    Carbon::setTestNow();
});

it('numbers orders sequentially per business day', function () {
    Carbon::setTestNow('2026-10-21 16:59:00'); // 23:59 WIB
    $first = postOrder(['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->hydrococo->id, 'quantity' => 1]]]);
    $second = postOrder(['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->hydrococo->id, 'quantity' => 1]]]);
    Carbon::setTestNow('2026-10-21 17:01:00'); // 00:01 WIB next day
    $third = postOrder(['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->hydrococo->id, 'quantity' => 1]]]);
    Carbon::setTestNow();

    expect($first->json('data.order_number'))->toBe('INV-20261021-0001')
        ->and($second->json('data.order_number'))->toBe('INV-20261021-0002')
        ->and($third->json('data.order_number'))->toBe('INV-20261022-0001');
});

it('rejects the whole order with per-item errors when any line lacks stock', function () {
    postOrder([
        'customer_id' => $this->customer->id,
        'items' => [
            ['product_id' => $this->hydrococo->id, 'quantity' => 1],
            ['product_id' => $this->aqua->id, 'quantity' => 5],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Stok tidak mencukupi')
        ->assertJsonPath('errors', ['items.1.quantity' => ['Stok Aqua 600ml tersisa 3, diminta 5']]);

    // All-or-nothing: the valid first line did not reserve anything either.
    expect($this->hydrococo->refresh()->stock)->toBe(10)
        ->and(Order::count())->toBe(0)
        ->and(StockMovement::query()->where('type', StockMovementType::OrderOut)->count())->toBe(0);
});

it('refuses client-supplied prices and totals', function () {
    postOrder([
        'customer_id' => $this->customer->id,
        'total_amount' => 1,
        'items' => [['product_id' => $this->hydrococo->id, 'quantity' => 1, 'unit_price' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors(['total_amount', 'items.0']);
});

it('validates the payload structure', function (array $payload, array $errors) {
    postOrder(array_merge(['customer_id' => $this->customer->id], $payload))
        ->assertStatus(422)
        ->assertJsonValidationErrors($errors);
})->with([
    'no items' => [['items' => []], ['items']],
    'zero quantity' => [['items' => [['product_id' => 1, 'quantity' => 0]]], ['items.0.quantity']],
    'duplicate product' => [['items' => [['product_id' => 1, 'quantity' => 1], ['product_id' => 1, 'quantity' => 1]]], ['items.0.product_id']],
    'unknown customer' => [['customer_id' => '9d2f6c1e-0000-4000-8000-000000000000', 'items' => [['product_id' => 1, 'quantity' => 1]]], ['customer_id']],
]);

it('rejects deleted or unknown products per item', function () {
    $this->aqua->delete();

    postOrder([
        'customer_id' => $this->customer->id,
        'items' => [
            ['product_id' => $this->aqua->id, 'quantity' => 1],
            ['product_id' => 999999, 'quantity' => 1],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.product_id', 'items.1.product_id']);
});

it('filters the order list by status and date', function () {
    $pending = placeOrder($this->cashier, [[$this->hydrococo, 1]], $this->customer);
    $other = placeOrder($this->cashier, [[$this->hydrococo, 1]], $this->customer);
    app(App\Domain\Order\OrderStateMachine::class)->pay($other, $this->cashier);

    $this->getJson('/api/orders?status=PENDING')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pending->id)
        ->assertJsonPath('data.0.items_count', 1);

    $today = now('Asia/Jakarta')->toDateString();
    $this->getJson("/api/orders?date_from={$today}&date_to={$today}")->assertJsonCount(2, 'data');
    $this->getJson('/api/orders?date_from=2000-01-01&date_to=2000-01-02')->assertJsonCount(0, 'data');
});

it('rate limits order creation per user', function () {
    config(['shop.rate_limits.orders_per_minute' => 2]);
    $payload = ['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->hydrococo->id, 'quantity' => 1]]];

    postOrder($payload)->assertCreated();
    postOrder($payload)->assertCreated();
    postOrder($payload)->assertStatus(429);
});
