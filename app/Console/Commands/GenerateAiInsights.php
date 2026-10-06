<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\AiInsightService;
use Illuminate\Console\Command;

class GenerateAiInsights extends Command
{
    protected $signature = 'app:generate-ai-insights';

    protected $description = 'Daily/monthly AI business insight generate karta hai har store ke liye';

    public function handle(AiInsightService $service)
    {
        $storeIds = Store::pluck('id');

        foreach ($storeIds as $storeId) {
            try {
                $service->generateBothInsights($storeId, 0); // 0 = combined, nightly auto-report
                $this->info("Insight generated for store #{$storeId}");
            } catch (\Throwable $e) {
                $this->error("Failed for store #{$storeId}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
