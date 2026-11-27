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
        Schema::table('prescription_registers', function (Blueprint $table) {
            $table->decimal('returned_qty', 12, 3)->default(0)->after('qty');
            $table->dateTime('last_returned_at')->nullable()->after('returned_qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prescription_registers', function (Blueprint $table) {
            $table->dropColumn(['returned_qty', 'last_returned_at']);
        });
    }
};
