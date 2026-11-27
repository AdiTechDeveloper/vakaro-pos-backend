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
        Schema::table('ai_insights', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->default(0)->after('store_id');
        });

        Schema::table('ai_insights', function (Blueprint $table) {
            $table->unique(['store_id', 'branch_id', 'insight_date', 'period_type'], 'ai_insights_unique_scope');

            $table->dropUnique(['store_id', 'insight_date', 'period_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_insights', function (Blueprint $table) {
            $table->unique(['store_id', 'insight_date', 'period_type']);
            $table->dropUnique('ai_insights_unique_scope');
            $table->dropColumn('branch_id');
        });
    }
};
