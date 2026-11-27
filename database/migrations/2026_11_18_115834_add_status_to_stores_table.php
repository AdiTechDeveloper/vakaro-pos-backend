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
        Schema::table('stores', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('logo');
            $table->string('status_reason', 255)->nullable()->after('status');
            $table->string('status_changed_at')->nullable()->after('status_reason');
            $table->string('subscription_ends_at')->nullable()->after('status_changed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_reason', 'status_changed_at', 'subscription_ends_at']);
        });
    }
};
