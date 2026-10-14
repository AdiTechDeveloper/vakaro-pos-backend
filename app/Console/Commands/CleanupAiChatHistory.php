<?php

namespace App\Console\Commands;

use App\Models\AiConversation;
use Illuminate\Console\Command;

class CleanupAiChatHistory extends Command
{
    protected $signature = 'ai-chat:cleanup';

    protected $description = 'Delete expired AI chat conversations';

    public function handle(): int
    {
        $days = max(1, (int) config('services.ai_chat_retention_days', 7));
        $cutoff = now()->subDays($days);

        $deleted = AiConversation::query()
            ->where(function ($query) use ($cutoff) {
                $query->where('last_message_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNull('last_message_at')
                            ->where('created_at', '<', $cutoff);
                    });
            })
            ->delete();

        $this->info("Deleted {$deleted} expired AI conversations.");

        return self::SUCCESS;
    }
}
