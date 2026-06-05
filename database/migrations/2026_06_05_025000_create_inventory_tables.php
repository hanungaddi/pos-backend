<?php

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
        // 1. stock_movements
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('tipe', 20); // 'sale', 'receive', 'adjustment', 'void', 'opname'
            $table->integer('kuantitas');
            $table->integer('stok_sebelum');
            $table->integer('stok_sesudah');
            $table->unsignedBigInteger('referensi_id')->nullable();
            $table->string('referensi_tipe', 50)->nullable(); // 'sale', 'receiving', 'opname', 'manual'
            $table->text('alasan')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index('product_id');
            $table->index('tipe');
            $table->index('created_at');
        });

        // 2. stock_receivings
        Schema::create('stock_receivings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('nomor_penerimaan', 50)->unique();
            $table->string('supplier', 255)->nullable();
            $table->string('nomor_faktur', 255)->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
        });

        // 3. stock_receiving_items
        Schema::create('stock_receiving_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_receiving_id')->constrained('stock_receivings')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products');
            $table->integer('kuantitas');
            $table->timestamps();
        });

        // 4. stock_opnames
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('nomor_opname', 50)->unique();
            $table->text('catatan')->nullable();
            $table->string('status', 20)->default('draft'); // 'draft', 'completed'
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // 5. stock_opname_items
        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained('stock_opnames')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products');
            $table->integer('stok_sistem');
            $table->integer('stok_fisik');
            $table->integer('selisih');
            $table->text('alasan')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
        Schema::dropIfExists('stock_receiving_items');
        Schema::dropIfExists('stock_receivings');
        Schema::dropIfExists('stock_movements');
    }
};
