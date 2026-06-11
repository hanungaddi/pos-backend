<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add tanggal_terima to stock_receivings
        Schema::table('stock_receivings', function (Blueprint $table) {
            $table->date('tanggal_terima')->nullable()->after('nomor_faktur');
        });

        // Populate tanggal_terima with created_at date for existing records
        DB::table('stock_receivings')->chunkById(100, function ($receivings) {
            foreach ($receivings as $receiving) {
                DB::table('stock_receivings')
                    ->where('id', $receiving->id)
                    ->update([
                        'tanggal_terima' => \Carbon\Carbon::parse($receiving->created_at)->toDateString()
                    ]);
            }
        });

        // 2. Add alasan to purchase_return_items
        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->string('alasan', 50)->nullable()->after('harga_beli');
        });

        // Add pricing fields to stock_receiving_items
        Schema::table('stock_receiving_items', function (Blueprint $table) {
            $table->boolean('update_harga_jual')->default(false)->after('harga_beli');
            $table->integer('harga_jual_baru')->nullable()->after('update_harga_jual');
            $table->decimal('margin_baru', 5, 2)->nullable()->after('harga_jual_baru');
        });

        // 3. Add resolution_type and catatan_penyelesaian to purchase_returns
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->string('resolution_type', 30)->nullable()->after('total_nominal');
            $table->text('catatan_penyelesaian')->nullable()->after('resolution_type');
        });

        // 4. Create supplier_credits table
        Schema::create('supplier_credits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('cascade');
            $table->bigInteger('amount')->default(0);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index('supplier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_credits');

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn(['resolution_type', 'catatan_penyelesaian']);
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->dropColumn('alasan');
        });

        Schema::table('stock_receivings', function (Blueprint $table) {
            $table->dropColumn('tanggal_terima');
        });

        Schema::table('stock_receiving_items', function (Blueprint $table) {
            $table->dropColumn(['update_harga_jual', 'harga_jual_baru', 'margin_baru']);
        });
    }
};
