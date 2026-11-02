<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('storefront_customer_id')->constrained('storefront_customers')->cascadeOnDelete();
            $t->string('label', 10)->default('home');   // home | work | other
            $t->string('name', 60);                     // saaman lene wale ka naam
            $t->string('mobile', 10);                   // saaman lene wale ka number
            $t->string('line1', 150);                   // flat / house / building
            $t->string('line2', 150);                   // area / street
            $t->string('landmark', 100)->nullable();
            $t->string('city', 60);
            $t->string('pincode', 6);
            $t->boolean('is_default')->default(false);
            $t->timestamps();

            $t->index(['storefront_customer_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_addresses');
    }
};