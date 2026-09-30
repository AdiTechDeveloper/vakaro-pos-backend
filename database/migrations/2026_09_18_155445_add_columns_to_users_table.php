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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_credentials')->default(true)->after('password');
            $table->unsignedInteger('failed_pin_attempts')->default(0)->after('pin_hash');
            $table->timestamp('locked_until')->nullable()->after('failed_pin_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_credentials');
            $table->dropColumn('failed_pin_attempts');
            $table->dropColumn('locked_until');
        });
    }
};
