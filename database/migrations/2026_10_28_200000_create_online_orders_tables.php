<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_no', 20)->unique();
            $t->foreignId('storefront_customer_id')->constrained('storefront_customers');

            $t->unsignedBigInteger('store_id');
            $t->unsignedBigInteger('branch_id');
            // store ki details order ke waqt ki copy (baad mein store badal jaye to order purana hi dikhe)
            $t->string('store_name', 150);
            $t->string('store_slug', 150);
            $t->string('store_phone', 20)->nullable();
            $t->string('store_address', 255)->nullable();

            $t->string('mode', 10);                              // delivery | pickup
            $t->string('status', 20)->default('placed');         // placed|accepted|preparing|ready|out_for_delivery|delivered|cancelled
            $t->string('payment_method', 20);                    // cod | pay_at_store (online payment baad mein)
            $t->string('payment_status', 20)->default('pending'); // pending | paid

            $t->decimal('subtotal', 12, 2);
            $t->decimal('delivery_fee', 12, 2)->default(0);
            $t->decimal('total', 12, 2);
            $t->decimal('total_saved', 12, 2)->default(0);

            $t->string('customer_name', 60);
            $t->string('customer_mobile', 10);

            // delivery address ki copy
            $t->string('addr_label', 10)->nullable();
            $t->string('addr_name', 60)->nullable();
            $t->string('addr_mobile', 10)->nullable();
            $t->string('addr_line1', 150)->nullable();
            $t->string('addr_line2', 150)->nullable();
            $t->string('addr_landmark', 100)->nullable();
            $t->string('addr_city', 60)->nullable();
            $t->string('addr_pincode', 6)->nullable();

            $t->string('note', 200)->nullable();
            $t->string('idempotency_key', 64);
            $t->timestamp('placed_at');
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 200)->nullable();
            $t->timestamps();

            $t->unique(['storefront_customer_id', 'idempotency_key']);
            $t->index(['branch_id', 'status']);
            $t->index(['storefront_customer_id', 'id']);
        });

        Schema::create('online_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('online_order_id')->constrained('online_orders')->cascadeOnDelete();
            $t->unsignedBigInteger('product_id');
            $t->string('name', 255);
            $t->string('price_unit', 10)->default('piece');  // piece | kg
            $t->decimal('qty', 10, 2);
            $t->decimal('price', 12, 2);
            $t->decimal('mrp', 12, 2)->nullable();
            $t->decimal('amount', 12, 2);
            $t->timestamps();

            $t->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_order_items');
        Schema::dropIfExists('online_orders');
    }
};