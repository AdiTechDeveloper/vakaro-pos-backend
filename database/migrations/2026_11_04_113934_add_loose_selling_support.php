<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * strip / loose (single unit) selling.
     *
     * - products.allow_loose / loose_unit: per-product switch.
     * - inventories.units_per_pack: pack size snapshot (1 for normal products). For loose-enabled
     *   products inventory qty / sold_qty / expired_qty are stored in single units (tablets...),
     *   and inventory prices are stored per single unit, so every existing "qty * cost_price"
     *   formula keeps working.
     * - sales_bill_lines.sale_unit / units_per_pack: how the line was sold (pack or loose).
     * - Price columns are widened so per-unit prices (e.g. 45 / 15 = 3.000000) keep enough precision.
     *   Existing values are not changed.
     */

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('allow_loose')->default(false)->after('units_per_pack');
            $table->string('loose_unit', 20)->nullable()->after('allow_loose');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->unsignedInteger('units_per_pack')->default(1)->after('qty');
        });

        Schema::table('sales_bill_lines', function (Blueprint $table) {
            $table->string('sale_unit', 10)->default('pack')->after('qty');
            $table->unsignedInteger('units_per_pack')->default(1)->after('sale_unit');
        });

        DB::statement('ALTER TABLE inventories
            MODIFY mrp DECIMAL(14,6) NOT NULL,
            MODIFY selling_price DECIMAL(14,6) NOT NULL,
            MODIFY cost_price DECIMAL(14,6) NOT NULL,
            MODIFY rate DECIMAL(16,6) NOT NULL DEFAULT 0');

        DB::statement('ALTER TABLE sales_bill_lines
            MODIFY rate DECIMAL(16,6) NOT NULL,
            MODIFY selling_price DECIMAL(14,6) NOT NULL DEFAULT 0,
            MODIFY original_price DECIMAL(14,6) NOT NULL DEFAULT 0,
            MODIFY override_price DECIMAL(14,6) NULL');

        DB::statement('ALTER TABLE sales_return_lines
            MODIFY rate DECIMAL(16,6) NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE sales_return_lines
            MODIFY rate DECIMAL(15,2) NOT NULL');

        DB::statement('ALTER TABLE sales_bill_lines
            MODIFY rate DECIMAL(15,2) NOT NULL,
            MODIFY selling_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            MODIFY original_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            MODIFY override_price DECIMAL(10,2) NULL');

        DB::statement('ALTER TABLE inventories
            MODIFY mrp DECIMAL(10,2) NOT NULL,
            MODIFY selling_price DECIMAL(10,2) NOT NULL,
            MODIFY cost_price DECIMAL(10,2) NOT NULL,
            MODIFY rate DECIMAL(15,4) NOT NULL DEFAULT 0');

        Schema::table('sales_bill_lines', function (Blueprint $table) {
            $table->dropColumn(['sale_unit', 'units_per_pack']);
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('units_per_pack');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['allow_loose', 'loose_unit']);
        });
    }
};
