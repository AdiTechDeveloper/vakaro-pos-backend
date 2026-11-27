<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Storefront (online) customer: sirf mobile se pehchaan. POS ke `customers` table se alag hai.
        // Order place hone par (Step D) ise POS customer se jodenge.
        Schema::create('storefront_customers', function (Blueprint $t) {
            $t->id();
            $t->string('mobile', 10)->unique();
            $t->string('name')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();
        });

        Schema::create('storefront_otps', function (Blueprint $t) {
            $t->id();
            $t->string('mobile', 10);
            $t->string('otp_hash');
            $t->timestamp('expires_at');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('verified_at')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();

            $t->index(['mobile', 'created_at']);
            $t->index(['ip', 'created_at']);
        });

        Schema::create('storefront_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('storefront_customer_id')->constrained('storefront_customers')->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('last_used_at')->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_sessions');
        Schema::dropIfExists('storefront_otps');
        Schema::dropIfExists('storefront_customers');
    }
};