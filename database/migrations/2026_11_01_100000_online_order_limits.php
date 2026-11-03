<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Store ka default: ek order mein ek product ki max quantity (khali = koi limit nahi)
        Schema::table('online_store_settings', function (Blueprint $t) {
            $t->unsignedInteger('max_qty_per_product')->nullable();
        });

        // Kisi product ka apna limit (store default se upar chalta hai). products table ko haath nahi lagaya.
        Schema::create('online_product_limits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('max_qty', 10, 2);   // kg wale items ke liye decimal bhi chalta hai
            $t->timestamps();

            $t->unique(['store_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_product_limits');
        Schema::table('online_store_settings', function (Blueprint $t) {
            $t->dropColumn('max_qty_per_product');
        });
    }
};