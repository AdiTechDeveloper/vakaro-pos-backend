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
        Schema::create('ai_insight_usage_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('branch_id')->default(0); // 0 = combined
            $table->date('usage_date');
            $table->unsignedTinyInteger('generate_count')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'branch_id', 'usage_date'], 'ai_insight_usage_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_insight_usage_limits');
    }
};
