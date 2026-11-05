<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $t) {
            // Delivery wale ki taaza location (sirf tab tak jab order raste mein ho). rider_ts = unix seconds.
            $t->decimal('rider_lat', 10, 7)->nullable();
            $t->decimal('rider_lng', 10, 7)->nullable();
            $t->unsignedBigInteger('rider_ts')->nullable();

            // Store ne time badhaya to customer ko dikhne wala karan
            $t->string('eta_note', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $t) {
            $t->dropColumn(['rider_lat', 'rider_lng', 'rider_ts', 'eta_note']);
        });
    }
};