<?php

declare(strict_types=1);

use App\Domain\Order\OrderStateMachine;
use App\Enums\StockMovementType;
use App\Jobs\ProcessPaidOrder;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->admin = admin();
    $this->cashier = cashier();
    $this->product = Product::factory()->withStock(10)->priced('12000.00')->create();
    $this->order = placeOrder($this->cashier, [[$this->product, 3]]);
});

it('walks the happy path PENDING → PAID → SHIPPED → COMPLETED and records history', function () {
    Queue::fake();

    Sanctum::actingAs($this->cashier);
    $this->postJson("/api/orders/{$this->order->id}/pay")->assertOk()->assertJsonPath('data.status', 'PAID');

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/orders/{$this->order->id}/ship")->assertOk()->assertJsonPath('data.status', 'SHIPPED');
    $this->postJson("/api/orders/{$this->order->id}/complete")->assertOk()->assertJsonPath('data.status', 'COMPLETED');

    $order = $this->order->refresh();
    expect($order->paid_at)->not->toBeNull()
        ->and($order->shipped_at)->not->toBeNull()
        ->and($order->completed_at)->not->toBeNull();

    $this->getJson("/api/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.status_history.*.to_status', ['PENDING', 'PAID', 'SHIPPED', 'COMPLETED'])
        ->assertJsonPath('data.allowed_actions', ['download_invoice']);

    Queue::assertPushed(ProcessPaidOrder::class, fn (ProcessPaidOrder $job) => $job->orderId === $order->id);
});

it('rejects illegal transitions with 409 Conflict', function () {
    Sanctum::actingAs($this->admin);

    // PENDING → SHIPPED skips payment.
    $this->postJson("/api/orders/{$this->order->id}/ship")
        ->assertStatus(409)
        ->assertJsonPath('success', false);

    // PENDING → COMPLETED.
    $this->postJson("/api/orders/{$this->order->id}/complete")->assertStatus(409);

    expect($this->order->refresh()->status->value)->toBe('PENDING');
});

it('rejects paying twice with 409', function () {
    Queue::fake();
    Sanctum::actingAs($this->cashier);

    $this->postJson("/api/orders/{$this->order->id}/pay")->assertOk();
    $this->postJson("/api/orders/{$this->order->id}/pay")->assertStatus(409);

    Queue::assertPushed(ProcessPaidOrder::class, 1);
});

it('enforces roles per transition on the backend', function () {
    Queue::fake();
    app(OrderStateMachine::class)->pay($this->order, $this->cashier);

    Sanctum::actingAs($this->cashier);
    $this->postJson("/api/orders/{$this->order->id}/ship")->assertForbidden();
    $this->postJson("/api/orders/{$this->order->id}/cancel", ['cancel_reason' => 'x reason'])->assertForbidden();

    app(OrderStateMachine::class)->ship($this->order, $this->admin);
    $this->postJson("/api/orders/{$this->order->id}/complete")->assertForbidden();

    expect($this->order->refresh()->status->value)->toBe('SHIPPED');
});

it('cancels a PENDING or PAID order, restores stock once and writes ledger + audit', function (bool $payFirst) {
    Queue::fake();
    if ($payFirst) {
        app(OrderStateMachine::class)->pay($this->order, $this->cashier);
    }
    expect($this->product->refresh()->stock)->toBe(7);

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/orders/{$this->order->id}/cancel", ['cancel_reason' => 'Customer membatalkan'])
        ->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED')
        ->assertJsonPath('data.cancel_reason', 'Customer membatalkan');

    expect($this->product->refresh()->stock)->toBe(10);

    $restore = StockMovement::query()->where('type', StockMovementType::CancelRestore)->sole();
    expect($restore->quantity_change)->toBe(3)
        ->and($restore->stock_after)->toBe(10)
        ->and($restore->reference_id)->toBe((string) $this->order->id);

    expect(AuditLog::query()->where('action', 'order.cancelled')->count())->toBe(1);

    // Second cancel: rejected, stock untouched.
    $this->postJson("/api/orders/{$this->order->id}/cancel", ['cancel_reason' => 'again'])->assertStatus(409);
    expect($this->product->refresh()->stock)->toBe(10)
        ->and(StockMovement::query()->where('type', StockMovementType::CancelRestore)->count())->toBe(1);
})->with(['from PENDING' => false, 'from PAID' => true]);

it('requires a cancel reason', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/orders/{$this->order->id}/cancel", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cancel_reason');
});

it('cannot cancel shipped or completed orders', function () {
    Queue::fake();
    $sm = app(OrderStateMachine::class);
    $sm->pay($this->order, $this->cashier);
    $sm->ship($this->order, $this->admin);

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/orders/{$this->order->id}/cancel", ['cancel_reason' => 'too late'])->assertStatus(409);

    $sm->complete($this->order, $this->admin);
    $this->postJson("/api/orders/{$this->order->id}/cancel", ['cancel_reason' => 'too late'])->assertStatus(409);

    expect($this->product->refresh()->stock)->toBe(7);
});

it('restores stock of a product that was soft-deleted after the order', function () {
    $this->product->delete();
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/orders/{$this->order->id}/cancel", ['cancel_reason' => 'Produk ditarik'])->assertOk();

    expect(Product::withTrashed()->find($this->product->id)->stock)->toBe(10);
});

it('serves the invoice only for paid orders', function () {
    Queue::fake();
    Sanctum::actingAs($this->cashier);

    $this->get("/api/orders/{$this->order->id}/invoice")->assertStatus(409);

    app(OrderStateMachine::class)->pay($this->order, $this->cashier);

    $response = $this->get("/api/orders/{$this->order->id}/invoice")->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($response->headers->get('Content-Disposition'))->toContain($this->order->order_number)
        ->and($response->getContent())->toContain($this->order->order_number);
});

it('exposes role-aware allowed actions as a UI hint', function () {
    Sanctum::actingAs($this->cashier);
    $this->getJson("/api/orders/{$this->order->id}")->assertJsonPath('data.allowed_actions', ['pay']);

    Sanctum::actingAs($this->admin);
    $this->getJson("/api/orders/{$this->order->id}")->assertJsonPath('data.allowed_actions', ['pay', 'cancel']);
});

it('never lets a mutation change an order of a stale model', function () {
    // Two in-memory copies of the same order (two requests). The state machine re-reads under lock.
    $copyA = Order::find($this->order->id);
    $copyB = Order::find($this->order->id);
    Queue::fake();

    app(OrderStateMachine::class)->cancel($copyA, $this->admin, 'first');

    expect(fn () => app(OrderStateMachine::class)->pay($copyB, $this->cashier))
        ->toThrow(App\Exceptions\InvalidOrderTransitionException::class);
});
