<?php
# database/migrations/2026_06_10_000004_create_purchase_returns_and_items_tables.php

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
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('nomor_retur', 50)->unique();
            $table->foreignId('stock_receiving_id')->nullable()->constrained('stock_receivings')->onDelete('set null');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->onDelete('set null');
            $table->date('tanggal_retur');
            $table->bigInteger('total_nominal')->default(0);
            $table->text('catatan')->nullable();
            $table->string('status', 20)->default('draft'); // 'draft', 'completed'
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index('stock_receiving_id');
            $table->index('supplier_id');
            $table->index('status');
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->integer('kuantitas');
            $table->integer('harga_beli');
            $table->timestamps();

            $table->index('purchase_return_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
