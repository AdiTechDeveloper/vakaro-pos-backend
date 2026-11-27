<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_store_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->onDelete('cascade');

            // Public identity (store.vakro.in/s/{slug})
            $table->string('slug')->unique();
            $table->string('display_name')->nullable();
            $table->string('logo')->nullable();
            $table->string('banner')->nullable();
            $table->text('about')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            // Kya store online dikh raha hai
            $table->boolean('is_online_enabled')->default(false)->index();
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();

            // Online stock kis branch se jayega + kitna % online ke liye
            $table->foreignId('fulfil_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedTinyInteger('online_stock_percent')->default(50);

            // Delivery / pickup
            $table->boolean('accepts_delivery')->default(true);
            $table->boolean('accepts_pickup')->default(true);
            $table->decimal('min_order_amount', 10, 2)->default(0);
            $table->decimal('delivery_charge', 10, 2)->default(0);
            $table->decimal('free_delivery_above', 10, 2)->nullable();
            $table->unsignedSmallInteger('prep_minutes')->default(30);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_settings');
    }
};
