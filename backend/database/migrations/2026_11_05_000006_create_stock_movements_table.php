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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->enum('type', ['INITIAL', 'ORDER_OUT', 'CANCEL_RESTORE', 'ADJUSTMENT']);
            $table->integer('quantity_change');
            $table->integer('stock_after');
            $table->string('reference_type', 30)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');

            $table->index(['product_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_stock_after_non_negative CHECK (stock_after >= 0)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_change_non_zero CHECK (quantity_change <> 0)');

        /*
         * Defence in depth for "cancel twice must not restore stock twice":
         * the state machine already serialises cancellation with a row lock,
         * and the database refuses a second restore for the same order + product.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX stock_movements_single_restore_idx
            ON stock_movements (reference_type, reference_id, product_id)
            WHERE type = 'CANCEL_RESTORE'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
