<?php
# database/migrations/2026_06_10_000002_create_purchase_orders_and_items_tables.php

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
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('nomor_po', 50)->unique();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->onDelete('set null');
            $table->string('supplier_name', 255)->nullable();
            $table->date('tanggal_po');
            $table->string('status', 20)->default('draft'); // 'draft', 'ordered', 'received', 'cancelled'
            $table->bigInteger('nilai_estimasi')->default(0);
            $table->text('catatan')->nullable();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index('supplier_id');
            $table->index('status');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->integer('kuantitas');
            $table->integer('kuantitas_diterima')->default(0);
            $table->integer('harga_estimasi')->default(0);
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
