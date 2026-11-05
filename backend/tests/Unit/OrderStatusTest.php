<?php

declare(strict_types=1);

use App\Domain\Order\OrderStatus;

it('allows exactly the transitions of the specification', function (OrderStatus $from, OrderStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'PENDING → PAID' => [OrderStatus::Pending, OrderStatus::Paid, true],
    'PENDING → CANCELLED' => [OrderStatus::Pending, OrderStatus::Cancelled, true],
    'PAID → SHIPPED' => [OrderStatus::Paid, OrderStatus::Shipped, true],
    'PAID → CANCELLED' => [OrderStatus::Paid, OrderStatus::Cancelled, true],
    'SHIPPED → COMPLETED' => [OrderStatus::Shipped, OrderStatus::Completed, true],

    'PENDING → SHIPPED (skip payment)' => [OrderStatus::Pending, OrderStatus::Shipped, false],
    'PENDING → COMPLETED' => [OrderStatus::Pending, OrderStatus::Completed, false],
    'PAID → PENDING (backwards)' => [OrderStatus::Paid, OrderStatus::Pending, false],
    'PAID → COMPLETED (skip shipping)' => [OrderStatus::Paid, OrderStatus::Completed, false],
    'SHIPPED → CANCELLED' => [OrderStatus::Shipped, OrderStatus::Cancelled, false],
    'COMPLETED → CANCELLED' => [OrderStatus::Completed, OrderStatus::Cancelled, false],
    'CANCELLED → CANCELLED (double cancel)' => [OrderStatus::Cancelled, OrderStatus::Cancelled, false],
    'CANCELLED → PAID' => [OrderStatus::Cancelled, OrderStatus::Paid, false],
    'PAID → PAID (double pay)' => [OrderStatus::Paid, OrderStatus::Paid, false],
]);

it('treats COMPLETED and CANCELLED as final states', function () {
    expect(OrderStatus::Completed->isFinal())->toBeTrue()
        ->and(OrderStatus::Cancelled->isFinal())->toBeTrue()
        ->and(OrderStatus::Pending->isFinal())->toBeFalse();
});

it('counts only paid, shipped and completed orders as revenue', function () {
    expect(OrderStatus::revenueValues())->toBe(['PAID', 'SHIPPED', 'COMPLETED']);
});

it('maps each status to the timestamp column filled on entry', function () {
    expect(OrderStatus::Paid->timestampColumn())->toBe('paid_at')
        ->and(OrderStatus::Shipped->timestampColumn())->toBe('shipped_at')
        ->and(OrderStatus::Completed->timestampColumn())->toBe('completed_at')
        ->and(OrderStatus::Cancelled->timestampColumn())->toBe('cancelled_at')
        ->and(OrderStatus::Pending->timestampColumn())->toBeNull();
});
