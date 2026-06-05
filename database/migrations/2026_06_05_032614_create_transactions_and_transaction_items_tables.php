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
        // Drop legacy tables if they exist
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');
            $table->string('nomor_transaksi', 30)->unique();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('pajak')->default(0);
            $table->unsignedBigInteger('diskon')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('status', 15)->default('draft'); // 'draft', 'hold', 'completed', 'void'
            $table->string('metode_pembayaran', 10)->nullable(); // 'cash', 'card', 'split'
            $table->unsignedBigInteger('nominal_bayar')->nullable();
            $table->unsignedBigInteger('kembalian')->nullable();
            
            // Card payment details
            $table->string('jenis_kartu', 10)->nullable(); // 'debit', 'kredit'
            $table->string('nomor_kartu_akhir', 4)->nullable();
            $table->string('referensi_edc', 50)->nullable();
            
            // Void fields
            $table->text('catatan_void')->nullable();
            $table->foreignId('void_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('voided_at')->nullable();
            
            // Offline fields
            $table->boolean('is_offline')->default(false);
            $table->uuid('offline_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            
            $table->timestamps();

            $table->index('store_id');
            $table->index('user_id');
            $table->index('nomor_transaksi');
            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->string('nama_produk', 200);
            $table->string('barcode', 50)->nullable();
            $table->unsignedBigInteger('harga_satuan');
            $table->integer('kuantitas');
            $table->unsignedBigInteger('subtotal');
            $table->boolean('is_taxable')->default(true);
            $table->unsignedBigInteger('diskon_item')->default(0);
            $table->timestamps();

            $table->index('transaction_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
        Schema::dropIfExists('transactions');
    }
};
