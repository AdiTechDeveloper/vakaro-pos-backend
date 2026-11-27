<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer ke saved address ka exact pin (map se). Manual address mein khali rahega.
        Schema::table('storefront_addresses', function (Blueprint $t) {
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
        });

        // Order ke time ki copy: baad mein customer address badle to chalu order ka pin na badle
        Schema::table('online_orders', function (Blueprint $t) {
            $t->decimal('addr_lat', 10, 7)->nullable();
            $t->decimal('addr_lng', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('storefront_addresses', function (Blueprint $t) {
            $t->dropColumn(['lat', 'lng']);
        });

        Schema::table('online_orders', function (Blueprint $t) {
            $t->dropColumn(['addr_lat', 'addr_lng']);
        });
    }
};