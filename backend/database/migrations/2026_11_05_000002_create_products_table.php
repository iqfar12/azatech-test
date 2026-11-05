<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enables trigram indexes so `ILIKE '%term%'` search does not need a sequential scan.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            // Unique across soft-deleted rows too: a code is never reused, so old orders stay unambiguous.
            $table->string('product_code', 30)->unique();
            $table->string('product_name', 150);
            $table->integer('stock')->default(0);
            $table->decimal('price', 12, 2);
            $table->integer('low_stock_threshold')->default(5);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // Last line of defence against overselling: even a buggy code path cannot persist negative stock.
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_non_negative CHECK (stock >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_positive CHECK (price > 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_threshold_non_negative CHECK (low_stock_threshold >= 0)');

        DB::statement('CREATE INDEX products_name_trgm_idx ON products USING gin (product_name gin_trgm_ops)');
        DB::statement('CREATE INDEX products_code_trgm_idx ON products USING gin (product_code gin_trgm_ops)');
        // Dashboard "low stock" counter only looks at live products.
        DB::statement('CREATE INDEX products_low_stock_idx ON products (stock, low_stock_threshold) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
