<?php
# database/migrations/2026_06_10_000001_create_product_price_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_price_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->integer('harga_beli_lama');
            $table->integer('harga_beli_baru');
            $table->integer('harga_jual_lama');
            $table->integer('harga_jual_baru');
            $table->decimal('margin_lama', 15, 2)->nullable();
            $table->decimal('margin_baru', 15, 2)->nullable();
            $table->string('sumber', 50)->default('manual'); // 'manual', 'receiving', 'import'
            $table->unsignedBigInteger('referensi_id')->nullable(); // e.g. stock_receiving_id
            $table->string('catatan', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('product_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_price_logs');
    }
};
