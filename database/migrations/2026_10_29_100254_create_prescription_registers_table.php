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
        Schema::create('prescription_registers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('sales_bill_id');
            $table->unsignedBigInteger('sales_bill_line_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('inventory_id')->nullable();
            $table->string('batch_no', 50)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('drug_schedule', 10)->default('none');
            $table->decimal('qty', 12, 3);
            $table->string('patient_name', 150);
            $table->unsignedSmallInteger('patient_age')->nullable();
            $table->string('patient_address')->nullable();
            $table->string('doctor_name', 150);
            $table->string('doctor_reg_no', 100)->nullable();
            $table->string('prescription_no', 100)->nullable();
            $table->date('prescription_date')->nullable();
            $table->unsignedBigInteger('dispensed_by')->nullable();
            $table->dateTime('dispensed_at');
            $table->timestamps();

            $table->index(['store_id', 'drug_schedule', 'dispensed_at']);
            $table->index('sales_bill_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescription_registers');
    }
};
