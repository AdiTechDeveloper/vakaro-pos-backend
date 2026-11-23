<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Online store product page ke liye (Blinkit jaisa):
     *  - description: lamba text
     *  - product_info: JSON [{label, value}] jaise Unit, Shelf Life, Storage Tips, FSSAI ...
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('description')->nullable()->after('image');
            $table->json('product_info')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['description', 'product_info']);
        });
    }
};