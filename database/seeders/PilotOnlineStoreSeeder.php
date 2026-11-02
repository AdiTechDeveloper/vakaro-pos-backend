<?php

namespace Database\Seeders;

use App\Services\OnlineStockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pilot store ko online enable karta hai.
 * Chalane se pehle neeche STORE_ID aur PINCODES apne hisaab se badlo.
 *
 *   php artisan db:seed --class=PilotOnlineStoreSeeder
 */
class PilotOnlineStoreSeeder extends Seeder
{
    private const STORE_ID = null;
private const PINCODES = ['380001', '380002']; // apne pilot store ke serviceable pincodes
    private const PERCENT = 50;

    public function run(OnlineStockService $stock): void
    {
        $store = self::STORE_ID
            ? DB::table('stores')->where('id', self::STORE_ID)->first()
            : DB::table('stores')->orderBy('id')->first();

        if (!$store) {
            $this->command->error('Koi store nahi mila.');
            return;
        }

        $branch = DB::table('branches')->where('store_id', $store->id)->orderBy('id')->first();

        if (!$branch) {
            $this->command->error("Store {$store->id} ki koi branch nahi hai.");
            return;
        }

        $now = now();

        DB::table('online_store_settings')->updateOrInsert(
            ['store_id' => $store->id],
            [
                'slug' => Str::slug($store->name ?? ('store-' . $store->id)),
                'is_online_enabled' => true,
                'fulfil_branch_id' => $branch->id,
                'online_stock_percent' => self::PERCENT,
                'accepts_delivery' => true,
                'accepts_pickup' => true,
                'min_order_amount' => 0,
                'delivery_charge' => 0,
                'prep_minutes' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        foreach (self::PINCODES as $pin) {
            DB::table('store_service_pincodes')->updateOrInsert(
                ['store_id' => $store->id, 'pincode' => $pin],
                ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $count = $stock->allocateForStore($store->id);

        $this->command->info("Store {$store->id} online enable hua, {$count} products ka online stock allocate hua.");
    }
}
