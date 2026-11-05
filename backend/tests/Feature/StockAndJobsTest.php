<?php

declare(strict_types=1);

use App\Domain\Order\OrderStateMachine;
use App\Jobs\ProcessPaidOrder;
use App\Jobs\SendLowStockAlert;
use App\Mail\LowStockAlertMail;
use App\Mail\OrderPaidMail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\LowStockAlert;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

describe('stock adjustment (stock opname)', function () {
    it('adjusts stock with a mandatory note and writes ledger + audit', function () {
        $admin = actingAsRole('admin');
        $product = Product::factory()->withStock(10)->create();

        $this->postJson("/api/products/{$product->id}/stock-adjustments", ['quantity_change' => 50, 'note' => 'Restock gudang'])
            ->assertCreated()
            ->assertJsonPath('data.movement.type', 'ADJUSTMENT')
            ->assertJsonPath('data.movement.stock_after', 60)
            ->assertJsonPath('data.movement.created_by.id', $admin->id)
            ->assertJsonPath('data.product.stock', 60);

        $this->postJson("/api/products/{$product->id}/stock-adjustments", ['quantity_change' => -3, 'note' => 'Stock opname bulanan'])
            ->assertCreated()
            ->assertJsonPath('data.product.stock', 57);

        expect(AuditLog::query()->where('action', 'stock.adjusted')->count())->toBe(2);
    });

    it('never lets stock go below zero', function () {
        actingAsRole('admin');
        $product = Product::factory()->withStock(2)->create();

        $this->postJson("/api/products/{$product->id}/stock-adjustments", ['quantity_change' => -3, 'note' => 'rusak'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity_change');

        expect($product->refresh()->stock)->toBe(2);
    });

    it('requires a note and a non-zero change', function () {
        actingAsRole('admin');
        $product = Product::factory()->create();

        $this->postJson("/api/products/{$product->id}/stock-adjustments", ['quantity_change' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity_change', 'note']);
    });

    it('lists the ledger of a product newest first', function () {
        $admin = actingAsRole('admin');
        $product = Product::factory()->withStock(20)->create();
        placeOrder($admin, [[$product, 2]]);
        placeOrder($admin, [[$product, 3]]);

        $this->getJson("/api/products/{$product->id}/stock-movements")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.quantity_change', -3)
            ->assertJsonPath('data.0.stock_after', 15)
            ->assertJsonPath('data.1.stock_after', 18);
    });

    it('keeps the ledger consistent: the sum of movements equals the stock', function () {
        $admin = admin();
        $product = app(App\Services\ProductService::class)->create([
            'product_code' => 'L0001', 'product_name' => 'Ledger', 'price' => '1000', 'stock' => 30,
        ], $admin);

        $order = placeOrder($admin, [[$product, 4]]);
        app(App\Services\StockService::class)->adjust($product, 10, 'restock', $admin);
        Queue::fake();
        app(OrderStateMachine::class)->cancel($order, $admin, 'batal');

        $sum = (int) StockMovement::query()->where('product_id', $product->id)->sum('quantity_change');
        expect($sum)->toBe($product->refresh()->stock)->toBe(40);
    });
});

describe('background jobs', function () {
    it('dispatches the invoice job only after the PAID transaction commits', function () {
        Queue::fake();
        $cashier = cashier();
        $order = placeOrder($cashier, [[Product::factory()->withStock(5)->create(), 1]]);

        app(OrderStateMachine::class)->pay($order, $cashier);

        Queue::assertPushedOn('notifications', ProcessPaidOrder::class);
    });

    it('e-mails the invoice once even if the job runs twice', function () {
        Mail::fake();
        Queue::fake();
        $cashier = cashier();
        $customer = Customer::factory()->create(['email' => 'budi@example.com']);
        $order = placeOrder($cashier, [[Product::factory()->withStock(5)->create(), 1]], $customer);
        app(OrderStateMachine::class)->pay($order, $cashier);

        $job = new ProcessPaidOrder($order->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']); // retry / duplicate delivery

        Mail::assertSent(OrderPaidMail::class, 1);
        Mail::assertSent(OrderPaidMail::class, fn (OrderPaidMail $mail) => $mail->hasTo('budi@example.com'));
        expect($order->refresh()->invoice_sent_at)->not->toBeNull();
    });

    it('skips the invoice when the order was cancelled before the worker ran', function () {
        Mail::fake();
        Queue::fake();
        $admin = admin();
        $order = placeOrder($admin, [[Product::factory()->withStock(5)->create(), 1]]);
        app(OrderStateMachine::class)->pay($order, $admin);
        app(OrderStateMachine::class)->cancel($order, $admin, 'batal');

        app()->call([new ProcessPaidOrder($order->id), 'handle']);

        Mail::assertNothingSent();
    });

    it('configures retries with backoff', function () {
        $job = new ProcessPaidOrder(1);

        expect($job->tries)->toBe(5)->and($job->backoff())->toBe([10, 30, 90, 300]);
    });

    it('alerts admins once when stock crosses below the threshold', function () {
        Mail::fake();
        $admin = admin();
        $product = Product::factory()->withStock(6, threshold: 5)->create();

        placeOrder($admin, [[$product, 1]]); // 6 → 5: not below 5 yet
        expect(LowStockAlert::count())->toBe(0);

        placeOrder($admin, [[$product, 1]]); // 5 → 4: crosses → alert
        placeOrder($admin, [[$product, 1]]); // 4 → 3: already below → no new alert

        expect(LowStockAlert::count())->toBe(1);
        Mail::assertSent(LowStockAlertMail::class, 1);

        // Re-running the job for the same movement does not e-mail again.
        $alert = LowStockAlert::query()->sole();
        app()->call([new SendLowStockAlert($product->id, $alert->stock_movement_id), 'handle']);
        Mail::assertSent(LowStockAlertMail::class, 1);
    });
});
