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
        Schema::table('products', function (Blueprint $table) {
            $table->string('salt_composition')->nullable()->after('name');
            $table->string('manufacturer')->nullable()->after('salt_composition');
            $table->string('drug_schedule', 10)->default('none')->after('manufacturer'); // none, G, H, H1, X
            $table->boolean('requires_prescription')->default(false)->after('drug_schedule');
            $table->string('pack_type', 30)->nullable()->after('requires_prescription'); // strip, bottle, tube...
            $table->unsignedInteger('units_per_pack')->nullable()->after('pack_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'salt_composition', 'manufacturer', 'drug_schedule',
                'requires_prescription', 'pack_type', 'units_per_pack',
            ]);
        });
    }
};
