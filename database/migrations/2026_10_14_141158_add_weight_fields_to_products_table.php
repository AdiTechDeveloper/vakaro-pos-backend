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
            $table->enum('sale_type', ['unit', 'weight'])->default('unit')->after('name');
            $table->string('plu_code', 10)->nullable()->after('barcode');
            $table->decimal('price_per_kg', 10, 2)->nullable()->after('cost_price');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['store_id', 'plu_code'], 'products_store_plu_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_store_plu_unique');
            $table->dropColumn(['sale_type', 'plu_code', 'price_per_kg']);
        });
    }
};
