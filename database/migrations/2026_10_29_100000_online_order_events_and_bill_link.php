<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('sales_bill_id')->nullable()->after('cancel_reason'); // deliver hone par bana POS bill
            $t->timestamp('delivered_at')->nullable()->after('sales_bill_id');
        });

        // Har status badlav ka record: customer ko timeline dikhane aur "kisne kya kiya" ke liye
        Schema::create('online_order_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('online_order_id')->constrained('online_orders')->cascadeOnDelete();
            $t->string('status', 20);
            $t->string('actor', 10);                       // customer | staff | system
            $t->unsignedBigInteger('user_id')->nullable(); // staff ki id
            $t->string('note', 200)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['online_order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_order_events');
        Schema::table('online_orders', function (Blueprint $t) {
            $t->dropColumn(['sales_bill_id', 'delivered_at']);
        });
    }
};