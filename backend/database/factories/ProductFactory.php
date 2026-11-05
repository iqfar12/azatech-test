<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Note: factories write `stock` directly (factories are unguarded) and therefore
 * create no INITIAL ledger row. Application code always goes through StockService;
 * the seeder uses ProductService so seeded data has a complete ledger.
 *
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_code' => 'P'.fake()->unique()->numerify('#####'),
            'product_name' => ucwords(fake()->words(2, true)).' '.fake()->randomElement(['150ml', '250ml', '500g', '1kg', 'Pack']),
            'stock' => fake()->numberBetween(20, 200),
            'price' => fake()->randomElement(['3500.00', '5000.00', '12000.00', '15500.00', '27000.00', '45000.00']),
            'low_stock_threshold' => 5,
        ];
    }

    public function withStock(int $stock, int $threshold = 5): self
    {
        return $this->state(['stock' => $stock, 'low_stock_threshold' => $threshold]);
    }

    public function priced(string $price): self
    {
        return $this->state(['price' => $price]);
    }
}
