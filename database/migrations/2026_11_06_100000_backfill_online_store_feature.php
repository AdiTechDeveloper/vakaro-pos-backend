<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ab storefront par store tabhi dikhta hai jab superadmin ne usse E-commerce
 * (feature "online_store") diya ho. Jo stores abhi online LIVE hain (settings row hai aur
 * is_online_enabled = 1), unko ye feature de do, taaki deploy ke baad wo achanak gayab na ho jayein.
 */
return new class extends Migration
{
    public function up(): void
    {
        $storeIds = DB::table('online_store_settings')
            ->where('is_online_enabled', 1)
            ->pluck('store_id');

        foreach ($storeIds as $storeId) {
            $exists = DB::table('store_features')
                ->where('store_id', $storeId)
                ->where('feature_key', 'online_store')
                ->exists();

            if (! $exists) {
                DB::table('store_features')->insert([
                    'store_id' => $storeId,
                    'feature_key' => 'online_store',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Jaan-boojhkar khali: pata nahi kaunsa feature pehle se diya hua tha.
    }
};