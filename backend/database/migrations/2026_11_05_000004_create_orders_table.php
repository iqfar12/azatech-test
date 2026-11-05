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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30)->unique();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['PENDING', 'PAID', 'SHIPPED', 'COMPLETED', 'CANCELLED'])->default('PENDING');
            $table->decimal('total_amount', 14, 2);
            $table->timestampTz('order_date');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            // Set by the invoice job once the notification e-mail went out (job idempotency guard).
            $table->timestampTz('invoice_sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['customer_id', 'order_date']);
            $table->index('created_by');
            $table->index(['status', 'order_date']);
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_non_negative CHECK (total_amount >= 0)');
        // Covering index for the sales report: range scan on order_date, filter on status,
        // and total_amount available without touching the heap (index-only scan).
        DB::statement('CREATE INDEX orders_report_idx ON orders (order_date, status) INCLUDE (total_amount)');
        DB::statement('CREATE INDEX orders_number_trgm_idx ON orders USING gin (order_number gin_trgm_ops)');

        /*
         * Per-day counter for INV-YYYYMMDD-NNNN. Incremented with an atomic
         * INSERT ... ON CONFLICT DO UPDATE ... RETURNING inside the order transaction,
         * so numbers are unique, gap-free on rollback, and need no Redis round trip.
         */
        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->date('sequence_date')->primary();
            $table->unsignedInteger('last_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
        Schema::dropIfExists('orders');
    }
};
