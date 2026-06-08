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
        Schema::table('stock_receivings', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('store_id')->constrained('suppliers')->onDelete('set null');
            $table->string('status', 20)->default('completed')->after('nomor_penerimaan'); // 'draft', 'completed'
            $table->integer('nilai_faktur')->nullable()->after('nomor_faktur'); // Invoice total amount
            $table->string('status_pembayaran', 20)->default('pending')->after('nilai_faktur'); // 'pending', 'paid'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_receivings', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn(['supplier_id', 'status', 'nilai_faktur', 'status_pembayaran']);
        });
    }
};
