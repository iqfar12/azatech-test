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
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('customer_name', 150);
            $table->string('customer_address', 255);
            $table->enum('gender', ['Pria', 'Wanita']);
            $table->date('birth_date')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 191)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('CREATE INDEX customers_name_trgm_idx ON customers USING gin (customer_name gin_trgm_ops)');
        DB::statement('CREATE INDEX customers_phone_idx ON customers (phone)');
        DB::statement('CREATE INDEX customers_email_idx ON customers (lower(email))');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
