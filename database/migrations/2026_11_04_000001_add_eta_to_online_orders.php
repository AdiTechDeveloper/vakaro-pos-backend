<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Order ke time ka snapshot: baad mein setting badle to chalu orders ka time na hile
        Schema::table('online_orders', function (Blueprint $t) {
            $t->unsignedSmallInteger('prep_minutes')->default(30);
            $t->unsignedSmallInteger('travel_minutes')->default(0);
        });

        // Is pincode tak pahunchne mein kitne minute lagte hain
        Schema::table('store_service_pincodes', function (Blueprint $t) {
            $t->unsignedSmallInteger('delivery_minutes')->default(20);
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $t) {
            $t->dropColumn(['prep_minutes', 'travel_minutes']);
        });

        Schema::table('store_service_pincodes', function (Blueprint $t) {
            $t->dropColumn('delivery_minutes');
        });
    }
};