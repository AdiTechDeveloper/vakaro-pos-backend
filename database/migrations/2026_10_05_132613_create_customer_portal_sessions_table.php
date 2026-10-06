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
    Schema::create('customer_portal_sessions', function (Blueprint $table) {
        $table->id();

        $table->foreignId('customer_id')
            ->constrained('customers')
            ->cascadeOnDelete();

        $table->string('token', 64)->unique();

        $table->timestamp('expires_at');

        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('customer_portal_sessions');
}

    
   
};
