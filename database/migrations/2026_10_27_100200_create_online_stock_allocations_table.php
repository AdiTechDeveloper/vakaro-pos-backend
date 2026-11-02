<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Online ke liye alag "bucket". `inventories` table ko touch nahi karte,
     * taaki POS ka stock logic bilkul same rahe.
     */
    public function up(): void
    {
        Schema::create('online_stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->decimal('allocated_qty', 10, 2)->default(0);
            $table->timestamp('allocated_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_stock_allocations');
    }
};
