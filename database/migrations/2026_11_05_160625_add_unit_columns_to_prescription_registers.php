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
            // Snapshot of how the medicine was sold: 'pack' (strip) or 'loose' (single unit)
            if (! Schema::hasColumn('prescription_registers', 'sale_unit')) {
                $table->string('sale_unit', 10)->default('pack')->after('qty');
            }

            // Pack size at the time of sale (1 for normal products)
            if (! Schema::hasColumn('prescription_registers', 'units_per_pack')) {
                $table->unsignedInteger('units_per_pack')->default(1)->after('sale_unit');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prescription_registers', function (Blueprint $table) {
            if (Schema::hasColumn('prescription_registers', 'units_per_pack')) {
                $table->dropColumn('units_per_pack');
            }
            if (Schema::hasColumn('prescription_registers', 'sale_unit')) {
                $table->dropColumn('sale_unit');
            }
        });
    }
};
