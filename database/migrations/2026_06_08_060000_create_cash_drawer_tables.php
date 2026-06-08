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
        Schema::create('cash_drawer_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');
            $table->unsignedBigInteger('opening_balance')->default(0);
            $table->unsignedBigInteger('expected_cash')->default(0);
            $table->unsignedBigInteger('actual_closing_balance')->nullable();
            $table->unsignedBigInteger('cash_sales_total')->default(0);
            $table->unsignedBigInteger('cash_refunds_total')->default(0);
            $table->unsignedBigInteger('cash_in_total')->default(0);
            $table->unsignedBigInteger('cash_out_total')->default(0);
            $table->bigInteger('difference')->nullable();
            $table->string('status', 20)->default('open'); // 'open', 'closed'
            $table->text('opening_note')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index('store_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('opened_at');
            $table->index('closed_at');
        });

        Schema::create('cash_drawer_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_drawer_session_id')->constrained('cash_drawer_sessions')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('type', 30); // 'opening', 'cash_in', 'cash_out', 'cash_sale', 'cash_refund', 'close'
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_before')->default(0);
            $table->unsignedBigInteger('balance_after')->default(0);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('cash_drawer_session_id');
            $table->index('user_id');
            $table->index('type');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_drawer_movements');
        Schema::dropIfExists('cash_drawer_sessions');
    }
};
