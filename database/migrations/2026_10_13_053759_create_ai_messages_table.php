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
    Schema::create('ai_messages', function (Blueprint $table) {
        $table->id();

        $table->foreignId('conversation_id')
            ->constrained('ai_conversations')
            ->cascadeOnDelete();

        $table->string('role', 20); // user or assistant
        $table->longText('content');

        // Report download details, if present.
        $table->json('metadata')->nullable();

        $table->timestamps();

        $table->index(['conversation_id', 'created_at']);
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
