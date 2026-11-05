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
        Schema::create('stock_writeoffs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('inventory_id');
            $table->string('batch_no', 50)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->decimal('loss_amount', 15, 2)->default(0);
            $table->string('reason', 30)->default('expired');
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('written_off_by')->nullable();
            $table->dateTime('written_off_at');
            $table->timestamps();

            $table->index(['store_id', 'written_off_at']);
            $table->index('inventory_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_writeoffs');
    }
};
