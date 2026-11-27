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
            $table->string('drug_licence_no')->nullable();
            $table->string('pharmacist_name')->nullable();
            $table->string('pharmacist_reg_no')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'drug_licence_no', 'pharmacist_name', 'pharmacist_reg_no',
            ]);
        });
    }
};
