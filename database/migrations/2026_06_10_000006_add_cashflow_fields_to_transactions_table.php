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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('tipe', 30)->default('sale')->after('user_id');
            $table->foreignId('cash_account_id')->nullable()->after('tipe')->constrained('cash_accounts')->onDelete('set null');
            $table->foreignId('target_account_id')->nullable()->after('cash_account_id')->constrained('cash_accounts')->onDelete('set null');
            $table->string('kategori', 50)->nullable()->after('target_account_id'); // 'penjualan', 'setoran_shift', 'pembelian_supplier', 'operasional', etc.
            $table->unsignedBigInteger('referensi_id')->nullable()->after('kategori');
            $table->string('referensi_tipe', 50)->nullable()->after('referensi_id');

            $table->index('tipe');
            $table->index(['referensi_id', 'referensi_tipe']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['cash_account_id']);
            $table->dropForeign(['target_account_id']);
            $table->dropColumn([
                'tipe',
                'cash_account_id',
                'target_account_id',
                'kategori',
                'referensi_id',
                'referensi_tipe',
            ]);
        });
    }
};
