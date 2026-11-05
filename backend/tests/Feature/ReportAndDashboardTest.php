<?php

declare(strict_types=1);

use App\Domain\Order\OrderStateMachine;
use App\Models\Product;
use App\Services\DashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->admin = admin();
    $this->sm = app(OrderStateMachine::class);
    $this->p1 = Product::factory()->withStock(100)->priced('10000.00')->create(['product_name' => 'Satu']);
    $this->p2 = Product::factory()->withStock(100)->priced('2500.00')->create(['product_name' => 'Dua']);

    // 2026-10-01 10:00 WIB: paid order, 2×Satu = 20.000
    Carbon::setTestNow('2026-10-01 03:00:00');
    $this->sm->pay(placeOrder($this->admin, [[$this->p1, 2]]), $this->admin);

    // 2026-10-01 23:30 WIB (still Oct 1st locally, Oct 1st 16:30 UTC): completed order, 1×Satu + 4×Dua = 20.000
    Carbon::setTestNow('2026-10-01 16:30:00');
    $o = placeOrder($this->admin, [[$this->p1, 1], [$this->p2, 4]]);
    $this->sm->pay($o, $this->admin);
    $this->sm->ship($o, $this->admin);
    $this->sm->complete($o, $this->admin);

    // 2026-10-03: pending order (not revenue) and cancelled order (not revenue)
    Carbon::setTestNow('2026-10-03 05:00:00');
    placeOrder($this->admin, [[$this->p1, 5]]);
    $this->sm->cancel(placeOrder($this->admin, [[$this->p2, 10]]), $this->admin, 'batal');

    // 2026-11-02: paid order 10×Dua = 25.000
    Carbon::setTestNow('2026-11-02 05:00:00');
    $this->sm->pay(placeOrder($this->admin, [[$this->p2, 10]]), $this->admin);

    Carbon::setTestNow();
});

it('aggregates sales per day in the business time zone, revenue statuses only, with empty days filled', function () {
    actingAsRole('admin');

    $this->getJson('/api/reports/sales?from=2026-10-01&to=2026-10-03&group_by=day')
        ->assertOk()
        ->assertJsonPath('data.rows', [
            ['period' => '2026-10-01', 'total_orders' => 2, 'revenue' => '40000.00'],
            ['period' => '2026-10-02', 'total_orders' => 0, 'revenue' => '0.00'],
            ['period' => '2026-10-03', 'total_orders' => 0, 'revenue' => '0.00'],
        ])
        ->assertJsonPath('data.summary', ['total_orders' => 2, 'revenue' => '40000.00']);
});

it('aggregates sales per month', function () {
    actingAsRole('admin');

    $this->getJson('/api/reports/sales?from=2026-10-01&to=2026-11-30&group_by=month')
        ->assertOk()
        ->assertJsonPath('data.rows', [
            ['period' => '2026-10', 'total_orders' => 2, 'revenue' => '40000.00'],
            ['period' => '2026-11', 'total_orders' => 1, 'revenue' => '25000.00'],
        ]);
});

it('ranks top products by quantity or revenue', function () {
    actingAsRole('admin');

    $this->getJson('/api/reports/top-products?from=2026-10-01&to=2026-11-30&limit=5')
        ->assertOk()
        ->assertJsonPath('data.0.product_name', 'Dua')
        ->assertJsonPath('data.0.quantity_sold', 14)
        ->assertJsonPath('data.0.revenue', '35000.00')
        ->assertJsonPath('data.1.product_name', 'Satu')
        ->assertJsonPath('data.1.quantity_sold', 3);

    $this->getJson('/api/reports/top-products?from=2026-10-01&to=2026-11-30&sort_by=revenue')
        ->assertJsonPath('data.0.product_name', 'Dua');

    $this->getJson('/api/reports/top-products?from=2026-10-01&to=2026-10-31&limit=1')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product_name', 'Dua') // October: Dua 4 pcs vs Satu 3 pcs
        ->assertJsonPath('data.0.quantity_sold', 4);
});

it('runs each report as a single query (no N+1)', function () {
    actingAsRole('admin');
    DB::enableQueryLog();

    $this->getJson('/api/reports/top-products?from=2026-10-01&to=2026-11-30')->assertOk();
    $reportQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'order_items'));

    expect($reportQueries)->toHaveCount(1);
});

it('validates report parameters and restricts reports to admins', function () {
    actingAsRole('admin');
    $this->getJson('/api/reports/sales?from=2026-10-05&to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('to');
    $this->getJson('/api/reports/sales?from=2026-10-01&to=2026-10-02&group_by=week')->assertStatus(422);

    actingAsRole('cashier');
    $this->getJson('/api/reports/sales?from=2026-10-01&to=2026-10-02')->assertForbidden();
    $this->getJson('/api/reports/top-products?from=2026-10-01&to=2026-10-02')->assertForbidden();
});

it('caches the dashboard summary and invalidates it after writes', function () {
    actingAsRole('cashier');

    $first = $this->getJson('/api/dashboard/summary')->assertOk()->json('data');
    expect(Cache::has(DashboardService::CACHE_KEY))->toBeTrue();

    // A new order (write) must invalidate the cached numbers.
    placeOrder($this->admin, [[$this->p1, 1]]);
    expect(Cache::has(DashboardService::CACHE_KEY))->toBeFalse();

    $second = $this->getJson('/api/dashboard/summary')->json('data');
    expect($second['pending_orders'])->toBe($first['pending_orders'] + 1);
});
