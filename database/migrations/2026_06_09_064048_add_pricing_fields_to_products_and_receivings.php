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
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('harga', 'harga_jual');
            $table->integer('harga_beli')->default(0)->after('stok');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('margin', 8, 2)->default(0.00)->after('harga_jual');
        });

        Schema::table('stock_receiving_items', function (Blueprint $table) {
            $table->integer('harga_beli')->default(0)->after('kuantitas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_receiving_items', function (Blueprint $table) {
            $table->dropColumn('harga_beli');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['harga_beli', 'margin']);
            $table->renameColumn('harga_jual', 'harga');
        });
    }
};

