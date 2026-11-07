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
        Schema::table('purchase_return_lines', function (Blueprint $table) {
            // Replacement only: the new stock received in exchange (single units) and its inventory row
            if (! Schema::hasColumn('purchase_return_lines', 'replacement_units')) {
                $table->decimal('replacement_units', 14, 3)->nullable()->after('units_per_pack');
            }
            if (! Schema::hasColumn('purchase_return_lines', 'replacement_inventory_id')) {
                $table->unsignedBigInteger('replacement_inventory_id')->nullable()->after('replacement_units');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_lines', function (Blueprint $table) {
            foreach (['replacement_inventory_id', 'replacement_units'] as $col) {
                if (Schema::hasColumn('purchase_return_lines', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
