<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $map = [
        'customers' => 'advance_payments',
        'stock_alerts' => 'reports_stock',
        'reports_financial' => 'reports_shift',
        'reports_purchase' => 'price_override',
        'products' => 'stock_alerts',
    ];

    public function up(): void
    {
        $this->copyKeys('store_features', 'store_id');
        $this->copyKeys('user_features', 'user_id');
    }

    private function copyKeys(string $table, string $ownerCol): void
    {
        $hasTimestamps = Schema::hasColumn($table, 'created_at');

        foreach ($this->map as $old => $new) {
            $owners = DB::table($table)->where('feature_key', $old)->pluck($ownerCol);

            foreach ($owners as $ownerId) {
                $exists = DB::table($table)
                    ->where($ownerCol, $ownerId)
                    ->where('feature_key', $new)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $row = [$ownerCol => $ownerId, 'feature_key' => $new];
                if ($hasTimestamps) {
                    $row['created_at'] = now();
                    $row['updated_at'] = now();
                }
                DB::table($table)->insert($row);
            }
        }
    }

    public function down(): void {}
};
