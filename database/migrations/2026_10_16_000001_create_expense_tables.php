<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->index();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_no', 20)->nullable()->unique();
            $table->unsignedBigInteger('store_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('category_id')->constrained('expense_categories');
            $table->date('expense_date')->index();
            $table->decimal('amount', 12, 2);
            $table->string('payment_mode', 10)->index(); // cash | bank | upi | card
            $table->string('paid_to', 120)->nullable();
            $table->string('reference_no', 60)->nullable();
            $table->string('note', 300)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};