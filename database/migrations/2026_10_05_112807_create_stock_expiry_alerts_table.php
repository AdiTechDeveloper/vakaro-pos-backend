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
    Schema::create('stock_expiry_alerts', function (Blueprint $table) {
        $table->id();

        $table->foreignId('branch_id')
            ->constrained('branches')
            ->cascadeOnDelete();

        $table->date('expiry_date');
        $table->date('alert_date');
        $table->string('severity');
        $table->integer('days_left');

        $table->timestamps();

        $table->index(['alert_date', 'expiry_date']);
        $table->index('severity');
    });
}

    /**
     * Reverse the migrations.
     */
  public function down(): void
{
    Schema::dropIfExists('stock_expiry_alerts');
}
};
