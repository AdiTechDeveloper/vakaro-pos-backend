<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_service_pincodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->string('pincode', 6)->index();
            // null ho to store ki default settings use hongi
            $table->decimal('delivery_charge', 10, 2)->nullable();
            $table->decimal('min_order_amount', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'pincode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_service_pincodes');
    }
};
