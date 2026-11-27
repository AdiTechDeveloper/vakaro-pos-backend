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
    if (
        Schema::hasTable('customer_portal_otps') &&
        Schema::hasColumn('customer_portal_otps', 'otp')
    ) {
        Schema::table('customer_portal_otps', function (Blueprint $table) {
            $table->string('otp', 6)->nullable()->change();
        });
    }

    if (
        Schema::hasTable('customer_portal_sessions') &&
        Schema::hasColumn('customer_portal_sessions', 'token')
    ) {
        Schema::table('customer_portal_sessions', function (Blueprint $table) {
            $table->string('token')->nullable()->change();
        });
    }
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
