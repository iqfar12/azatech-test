<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Order\OrderStateMachine;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStockException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Demo data: 1 admin, 1 cashier, 55 products, 30 customers and 150 orders spread
 * over the last 90 days with every status.
 *
 * Orders are created through the real services (OrderService, OrderStateMachine)
 * with a frozen clock, so stock, ledger, status history and order numbers are
 * exactly what production code would have produced — the seed data itself is a
 * consistency check.
 */
final class DatabaseSeeder extends Seeder
{
    private const PRODUCTS = [
        ['Hydrococo 150ml', '12000.00'], ['Hydrococo 250ml', '15500.00'], ['Aqua 600ml', '4000.00'],
        ['Aqua 1500ml', '7000.00'], ['Le Minerale 600ml', '3800.00'], ['Teh Botol Sosro 350ml', '5500.00'],
        ['Teh Pucuk Harum 350ml', '4000.00'], ['Pocari Sweat 500ml', '8500.00'], ['Coca-Cola 390ml', '6500.00'],
        ['Sprite 390ml', '6500.00'], ['Fanta Strawberry 390ml', '6500.00'], ['Good Day Cappuccino 250ml', '7500.00'],
        ['Kopi Kenangan Mantan 225ml', '9900.00'], ['Ultra Milk Coklat 250ml', '6800.00'], ['Ultra Milk Full Cream 1L', '19500.00'],
        ['Bear Brand 189ml', '10500.00'], ['Yakult 5x65ml', '11000.00'], ['Indomie Goreng', '3500.00'],
        ['Indomie Soto', '3300.00'], ['Mie Sedaap Goreng', '3400.00'], ['Pop Mie Ayam', '6000.00'],
        ['Beras Pandan Wangi 5kg', '78000.00'], ['Minyak Goreng Bimoli 2L', '38500.00'], ['Gula Pasir Gulaku 1kg', '17500.00'],
        ['Telur Ayam 10 butir', '28000.00'], ['Kecap Bango 520ml', '24500.00'], ['Saos Sambal ABC 335ml', '13500.00'],
        ['Royco Ayam 100g', '5500.00'], ['Masako Sapi 100g', '5300.00'], ['Garam Refina 250g', '4500.00'],
        ['Chitato Sapi Panggang 68g', '11500.00'], ['Qtela Singkong 60g', '8500.00'], ['Taro Net 65g', '9000.00'],
        ['Oreo Vanilla 133g', '9800.00'], ['Roma Kelapa 300g', '10500.00'], ['SilverQueen 58g', '16500.00'],
        ['Beng-Beng 20g', '2500.00'], ['Tango Wafer 130g', '8500.00'], ['Roti Tawar Sari Roti', '16000.00'],
        ['Sabun Lifebuoy 110g', '4500.00'], ['Shampoo Pantene 170ml', '27500.00'], ['Pepsodent 190g', '14500.00'],
        ['Sikat Gigi Formula', '9500.00'], ['Rinso Cair 750ml', '24000.00'], ['Sunlight 755ml', '18500.00'],
        ['Molto Pewangi 900ml', '21000.00'], ['Tisu Paseo 250 sheet', '15500.00'], ['Baygon Aerosol 600ml', '42000.00'],
        ['Baterai ABC AA 2pcs', '8000.00'], ['Kantong Plastik Besar', '1000.00'], ['Susu Kental Manis Frisian Flag 370g', '13000.00'],
        ['Nescafe Classic 50g', '22500.00'], ['Kapal Api Special 165g', '15500.00'], ['Teh Celup Sariwangi 25s', '7500.00'],
        ['Madu TJ 150g', '24000.00'],
    ];

    public function run(ProductService $products, OrderService $orders, OrderStateMachine $stateMachine): void
    {
        // No e-mails / jobs for historical demo orders.
        Bus::fake();
        Mail::fake();

        mt_srand(20261021); // deterministic demo data
        $now = Carbon::now();

        Carbon::setTestNow($now->copy()->subDays(100));

        $admin = User::query()->create([
            'name' => 'Admin Azatech',
            'email' => 'admin@azatech.id',
            'password' => (string) config('shop.seed.admin_password'),
            'role' => UserRole::Admin,
        ]);
        $cashier = User::query()->create([
            'name' => 'Kasir Azatech',
            'email' => 'cashier@azatech.id',
            'password' => (string) config('shop.seed.cashier_password'),
            'role' => UserRole::Cashier,
        ]);

        $productIds = [];
        foreach (self::PRODUCTS as $i => [$name, $price]) {
            $productIds[] = $products->create([
                'product_code' => sprintf('P%04d', $i + 1),
                'product_name' => $name,
                'price' => $price,
                // A few products start close to their threshold so low-stock alerts / counters have data.
                'low_stock_threshold' => $i % 11 === 0 ? 20 : 10,
                'stock' => $i % 11 === 0 ? mt_rand(28, 36) : mt_rand(150, 400),
            ], $admin)->id;
        }

        $customerIds = Customer::factory()->count(30)->create()->pluck('id')->all();

        // 150 orders, chronological so order numbers increase with time.
        $timestamps = [];
        for ($i = 0; $i < 150; $i++) {
            $timestamps[] = $now->copy()->subDays(mt_rand(0, 89))->setTime(mt_rand(8, 20), mt_rand(0, 59), mt_rand(0, 59));
        }
        usort($timestamps, fn (Carbon $a, Carbon $b) => $a <=> $b);

        foreach ($timestamps as $at) {
            if ($at->greaterThan($now)) {
                $at = $now->copy()->subMinutes(mt_rand(5, 120));
            }
            Carbon::setTestNow($at);

            $actor = mt_rand(0, 1) === 0 ? $admin : $cashier;
            $order = null;
            // A random basket may hit a product that is already low; just draw another basket.
            for ($attempt = 0; $attempt < 5 && $order === null; $attempt++) {
                try {
                    $order = $orders->create($actor, $customerIds[array_rand($customerIds)], $this->randomItems($productIds));
                } catch (InsufficientStockException) {
                    $order = null;
                }
            }
            if ($order === null) {
                continue;
            }

            $this->advance($order, $stateMachine, $admin, $actor, $at, $now);
        }

        Carbon::setTestNow();
        Cache::flush();
    }

    /**
     * @param  list<int>  $productIds
     * @return list<array{product_id: int, quantity: int}>
     */
    private function randomItems(array $productIds): array
    {
        $picked = (array) array_rand(array_flip($productIds), mt_rand(1, 4));

        return array_map(fn (int $id): array => ['product_id' => $id, 'quantity' => mt_rand(1, 3)], array_values($picked));
    }

    private function advance(Order $order, OrderStateMachine $sm, User $admin, User $actor, Carbon $at, Carbon $now): void
    {
        $ageDays = $at->diffInDays($now);
        $roll = mt_rand(1, 100);

        // Recent orders are still in progress; older ones are mostly completed.
        $target = match (true) {
            $ageDays < 3 => $roll <= 40 ? 'PENDING' : ($roll <= 70 ? 'PAID' : ($roll <= 90 ? 'SHIPPED' : 'CANCELLED')),
            default => $roll <= 60 ? 'COMPLETED' : ($roll <= 72 ? 'SHIPPED' : ($roll <= 80 ? 'PAID' : ($roll <= 85 ? 'PENDING' : 'CANCELLED'))),
        };

        $step = function (int $minutes) use ($at, $now): void {
            $next = $at->copy()->addMinutes($minutes);
            Carbon::setTestNow($next->greaterThan($now) ? $now : $next);
        };

        if ($target === 'CANCELLED') {
            if (mt_rand(0, 1) === 1) {
                $step(30);
                $order = $sm->pay($order, $actor);
            }
            $step(90);
            $sm->cancel($order, $admin, 'Customer membatalkan pesanan');

            return;
        }

        if ($target === 'PENDING') {
            return;
        }

        $step(20);
        $order = $sm->pay($order, $actor);
        if ($target === 'PAID') {
            return;
        }

        $step(60 * 24);
        $order = $sm->ship($order, $admin);
        if ($target === 'SHIPPED') {
            return;
        }

        $step(60 * 24 * 3);
        $sm->complete($order, $admin);
    }
}
