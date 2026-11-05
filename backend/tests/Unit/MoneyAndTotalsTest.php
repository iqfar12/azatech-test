<?php

declare(strict_types=1);

use App\Domain\Order\OrderTotalCalculator;
use App\Domain\Shared\Money;

describe('Money', function () {
    it('parses decimal strings exactly', function (string $input, int $minor, string $normalised) {
        $money = Money::fromDecimal($input);

        expect($money->minor)->toBe($minor)
            ->and($money->toDecimal())->toBe($normalised);
    })->with([
        ['12000.00', 1200000, '12000.00'],
        ['12000', 1200000, '12000.00'],
        ['0.5', 50, '0.50'],
        ['0.05', 5, '0.05'],
        ['999999999999.99', 99999999999999, '999999999999.99'],
        ['-2.50', -250, '-2.50'],
    ]);

    it('rejects amounts with more than two decimals or garbage', function (string $input) {
        Money::fromDecimal($input);
    })->with(['1.005', 'abc', '1,50', ''])->throws(InvalidArgumentException::class);

    it('does not suffer from floating point drift', function () {
        $total = Money::fromDecimal('0.10')->add(Money::fromDecimal('0.20'));

        expect($total->toDecimal())->toBe('0.30');
    });
});

describe('OrderTotalCalculator', function () {
    it('computes subtotals and the total from unit price × quantity', function () {
        $result = (new OrderTotalCalculator)->calculate([
            ['unit_price' => Money::fromDecimal('12000.00'), 'quantity' => 2],
            ['unit_price' => Money::fromDecimal('12000.00'), 'quantity' => 1],
        ]);

        expect($result['lines'][0]['subtotal']->toDecimal())->toBe('24000.00')
            ->and($result['lines'][1]['subtotal']->toDecimal())->toBe('12000.00')
            ->and($result['total']->toDecimal())->toBe('36000.00');
    });

    it('keeps cents exact across many lines', function () {
        $lines = array_fill(0, 3, ['unit_price' => Money::fromDecimal('3333.33'), 'quantity' => 3]);

        expect((new OrderTotalCalculator)->calculate($lines)['total']->toDecimal())->toBe('29999.97');
    });

    it('preserves the line keys so errors can point at items.N', function () {
        $result = (new OrderTotalCalculator)->calculate([
            3 => ['unit_price' => Money::fromDecimal('1.00'), 'quantity' => 1],
        ]);

        expect(array_keys($result['lines']))->toBe([3]);
    });

    it('rejects empty orders, zero quantities and non-positive prices', function (array $lines) {
        (new OrderTotalCalculator)->calculate($lines);
    })->with([
        'empty' => [[]],
        'zero qty' => [[['unit_price' => Money::fromDecimal('1.00'), 'quantity' => 0]]],
        'zero price' => [[['unit_price' => Money::fromDecimal('0'), 'quantity' => 1]]],
    ])->throws(InvalidArgumentException::class);
});
