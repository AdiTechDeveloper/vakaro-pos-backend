<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // role ab enum nahi, string: "delivery" role bhi chalega (superadmin/admin/manager/cashier/delivery)
            $t->string('role', 20)->default('cashier')->change();

            // Delivery person duty par hai ya nahi (assign sirf on-duty ko hota hai)
            $t->boolean('on_duty')->default(false);
            // Delivery screen khuli hone ka pata (har poll par update) -> POS ko "online / app closed" dikhane ke liye
            $t->timestamp('delivery_last_seen_at')->nullable();
        });

        Schema::table('online_orders', function (Blueprint $t) {
            // Customer ka delivery OTP (encrypted: customer ke order page par dikhane aur SMS dobara bhejne ke liye)
            $t->text('delivery_otp_enc')->nullable();
            $t->timestamp('delivery_otp_sent_at')->nullable();
            $t->unsignedTinyInteger('delivery_otp_attempts')->default(0);
            $t->unsignedTinyInteger('delivery_otp_resends')->default(0);
            $t->timestamp('delivery_otp_locked_until')->nullable();
            $t->boolean('delivery_otp_overridden')->default(false); // OTP ke bina manager ne deliver kiya

            // COD: delivery par kitna cash liya
            $t->decimal('cod_collected', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $t) {
            $t->dropColumn([
                'delivery_otp_enc',
                'delivery_otp_sent_at',
                'delivery_otp_attempts',
                'delivery_otp_resends',
                'delivery_otp_locked_until',
                'delivery_otp_overridden',
                'cod_collected',
            ]);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['on_duty', 'delivery_last_seen_at']);
        });
    }
};