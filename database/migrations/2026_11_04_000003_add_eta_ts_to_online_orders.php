<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ETA ko seedha unix number (seconds) ki tarah rakhte hain. Isme timezone ka koi chakkar hi nahi:
        // 5:30 ghante ka fark (364 minute wali galti) datetime column padhne se aata tha.
        Schema::table('online_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('eta_ts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $t) {
            $t->dropColumn('eta_ts');
        });
    }
};