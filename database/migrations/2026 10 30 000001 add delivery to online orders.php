<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_user_id')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropIndex(['delivery_user_id']);
            $table->dropColumn(['delivery_user_id', 'assigned_at']);
        });
    }
};