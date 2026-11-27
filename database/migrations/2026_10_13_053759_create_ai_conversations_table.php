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
    Schema::create('ai_conversations', function (Blueprint $table) {
        $table->id();

        $table->foreignId('user_id')
            ->constrained('users')
            ->cascadeOnDelete();

        // Null means all branches; otherwise a selected branch.
        $table->unsignedBigInteger('branch_id')->nullable();

        $table->string('title', 150)->default('New chat');

        $table->timestamp('last_message_at')->nullable()->index();

        $table->timestamps();

        $table->index(['user_id', 'updated_at']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
