
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customer_portal_otps')) {
            Schema::create('customer_portal_otps', function (Blueprint $table) {
                $table->id();
                $table->string('mobile', 10)->index();
                $table->string('otp_hash')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('verified_at')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamps();
            });
        } else {
            Schema::table('customer_portal_otps', function (Blueprint $table) {
                if (!Schema::hasColumn('customer_portal_otps', 'otp_hash')) {
                    $table->string('otp_hash')->nullable();
                }

                if (!Schema::hasColumn('customer_portal_otps', 'attempts')) {
                    $table->unsignedTinyInteger('attempts')->default(0);
                }
            });
        }

        if (!Schema::hasTable('customer_portal_sessions')) {
            Schema::create('customer_portal_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')
                    ->constrained('customers')
                    ->cascadeOnDelete();
                $table->string('token_hash', 64)->nullable()->unique();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        } else {
            Schema::table('customer_portal_sessions', function (Blueprint $table) {
                if (!Schema::hasColumn('customer_portal_sessions', 'token_hash')) {
                    $table->string('token_hash', 64)->nullable()->unique();
                }
            });
        }
    }

    public function down(): void
    {
        // Existing customer data ko rollback mein delete nahi karna.
    }
};
