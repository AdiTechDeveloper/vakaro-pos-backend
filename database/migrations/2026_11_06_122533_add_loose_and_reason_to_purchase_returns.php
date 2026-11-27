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
            // Pack size at the time of return (stock is stored in single units, returns in packs)
            if (! Schema::hasColumn('purchase_return_lines', 'units_per_pack')) {
                $table->unsignedInteger('units_per_pack')->default(1)->after('qty');
            }
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_returns', 'reason')) {
                $table->string('reason', 30)->nullable()->after('return_type');
            }
            if (! Schema::hasColumn('purchase_returns', 'notes')) {
                $table->string('notes', 500)->nullable()->after('reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_lines', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_return_lines', 'units_per_pack')) {
                $table->dropColumn('units_per_pack');
            }
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            foreach (['notes', 'reason'] as $col) {
                if (Schema::hasColumn('purchase_returns', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
