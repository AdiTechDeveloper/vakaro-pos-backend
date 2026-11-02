<?php

namespace App\Console\Commands;

use App\Models\OnlineStoreSetting;
use App\Services\OnlineStockService;
use Illuminate\Console\Command;

class AllocateOnlineStock extends Command
{
    protected $signature = 'online:allocate-stock {--store= : Sirf is store_id ke liye chalao}';

    protected $description = 'Online stock bucket ko physical stock ke percent se dobara bharo';

    public function handle(OnlineStockService $service): int
    {
        $query = OnlineStoreSetting::where('is_online_enabled', true)->whereNotNull('fulfil_branch_id');

        if ($storeId = $this->option('store')) {
            $query->where('store_id', $storeId);
        }

        $settings = $query->get();

        if ($settings->isEmpty()) {
            $this->warn('Koi online-enabled store nahi mila.');
            return self::SUCCESS;
        }

        foreach ($settings as $s) {
            $count = $service->allocateForStore($s->store_id);
            $this->info("Store {$s->store_id} ({$s->slug}): {$count} products allocate hue ({$s->online_stock_percent}%).");
        }

        return self::SUCCESS;
    }
}
