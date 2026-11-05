<?php

declare(strict_types=1);

use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockMovement;

describe('read access', function () {
    it('lets both roles list products with the pagination envelope', function (string $role) {
        actingAsRole($role);
        Product::factory()->count(15)->create();

        $this->getJson('/api/products?per_page=10&page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.total_pages', 2)
            ->assertJsonCount(5, 'data');
    })->with(['admin', 'cashier']);

    it('searches by name or code, case-insensitive', function () {
        actingAsRole('cashier');
        Product::factory()->create(['product_code' => 'P0001', 'product_name' => 'Hydrococo 150ml']);
        Product::factory()->create(['product_code' => 'X9999', 'product_name' => 'Aqua 600ml']);

        $this->getJson('/api/products?search=hydro')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_code', 'P0001');
        $this->getJson('/api/products?search=x99')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_name', 'Aqua 600ml');
    });

    it('sorts by a whitelisted column and rejects unknown ones', function () {
        actingAsRole('cashier');
        Product::factory()->priced('5000.00')->create();
        Product::factory()->priced('1000.00')->create();
        Product::factory()->priced('3000.00')->create();

        $prices = collect($this->getJson('/api/products?sort=-price')->json('data'))->pluck('price')->all();
        expect($prices)->toBe(['5000.00', '3000.00', '1000.00']);

        $this->getJson('/api/products?sort=password')->assertStatus(422)->assertJsonValidationErrors('sort');
    });

    it('returns 404 for unknown or malformed ids', function () {
        actingAsRole('cashier');

        $this->getJson('/api/products/999999')->assertStatus(404)->assertJsonPath('success', false);
        $this->getJson('/api/products/abc')->assertStatus(404);
    });
});

describe('admin management', function () {
    it('creates a product and records the opening stock in the ledger', function () {
        actingAsRole('admin');

        $response = $this->postJson('/api/products', [
            'product_code' => 'p0100',
            'product_name' => 'Hydrococo 150ml',
            'price' => 12000,
            'stock' => 10,
            'low_stock_threshold' => 5,
        ])->assertCreated()
            ->assertJsonPath('data.product_code', 'P0100')
            ->assertJsonPath('data.price', '12000.00')
            ->assertJsonPath('data.stock', 10);

        $movement = StockMovement::query()->where('product_id', $response->json('data.id'))->sole();
        expect($movement->type)->toBe(StockMovementType::Initial)
            ->and($movement->quantity_change)->toBe(10)
            ->and($movement->stock_after)->toBe(10);
    });

    it('validates product input', function () {
        actingAsRole('admin');
        Product::factory()->create(['product_code' => 'P0001']);

        $this->postJson('/api/products', [
            'product_code' => 'P0001',
            'product_name' => '',
            'price' => -5,
            'stock' => -1,
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['product_code', 'product_name', 'price', 'stock']);
    });

    it('does not allow changing stock through update (mass assignment protection)', function () {
        actingAsRole('admin');
        $product = Product::factory()->withStock(10)->create();

        $this->putJson("/api/products/{$product->id}", ['stock' => 9999])
            ->assertStatus(422)->assertJsonValidationErrors('stock');

        $this->putJson("/api/products/{$product->id}", ['product_name' => 'Renamed', 'price' => '15000.50'])
            ->assertOk()
            ->assertJsonPath('data.product_name', 'Renamed')
            ->assertJsonPath('data.price', '15000.50')
            ->assertJsonPath('data.stock', 10);
    });

    it('soft deletes and writes an audit log entry', function () {
        $admin = actingAsRole('admin');
        $product = Product::factory()->create();

        $this->deleteJson("/api/products/{$product->id}")->assertNoContent();

        expect(Product::find($product->id))->toBeNull()
            ->and(Product::withTrashed()->find($product->id))->not->toBeNull();
        $this->getJson("/api/products/{$product->id}")->assertStatus(404);

        $audit = AuditLog::query()->where('action', 'product.deleted')->sole();
        expect($audit->user_id)->toBe($admin->id)->and($audit->subject_id)->toBe((string) $product->id);
    });
});

describe('cashier restrictions (enforced by the backend)', function () {
    beforeEach(fn () => actingAsRole('cashier'));

    it('forbids creating products even with a valid payload', function () {
        $this->postJson('/api/products', [
            'product_code' => 'P0200', 'product_name' => 'Valid', 'price' => 1000,
        ])->assertForbidden()->assertJsonPath('success', false);
    });

    it('forbids update, delete, stock adjustment and ledger access', function () {
        $product = Product::factory()->create();

        $this->putJson("/api/products/{$product->id}", ['product_name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/products/{$product->id}")->assertForbidden();
        $this->postJson("/api/products/{$product->id}/stock-adjustments", ['quantity_change' => 5, 'note' => 'opname'])->assertForbidden();
        $this->getJson("/api/products/{$product->id}/stock-movements")->assertForbidden();

        expect(Product::find($product->id))->not->toBeNull();
    });
});
