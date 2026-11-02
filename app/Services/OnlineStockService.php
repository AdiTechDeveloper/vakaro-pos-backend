<?php

namespace App\Services;

use App\Models\OnlineStoreSetting;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OnlineStockService
{
    /**
     * Ek branch ka product-wise physical stock (subquery).
     *
     * Lot ka bacha hua stock = qty - sold_qty - expired_qty (kabhi negative nahi).
     * Soft-deleted lots aur expiry nikal chuke lots count nahi hote.
     */
    public function physicalSub(int $branchId): Builder
    {
        return DB::table('inventories')
            ->select('product_id', DB::raw('SUM(GREATEST(qty - sold_qty - expired_qty, 0)) as qty'))
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', today()->toDateString());
            })
            ->groupBy('product_id');
    }

    /**
     * Store ke saare (non-deleted) products ke liye online bucket bharo:
     * allocated_qty = physical * online_stock_percent / 100
     *   - piece (unit) items: neeche ki poori sankhya tak (floor)
     *   - weight items: 2 decimal tak neeche
     *
     * Return: kitne products update hue.
     */
    public function allocateForStore(int $storeId): int
    {
        $settings = OnlineStoreSetting::where('store_id', $storeId)->firstOrFail();

        if (!$settings->fulfil_branch_id) {
            throw new \RuntimeException("Store {$storeId}: fulfil_branch_id set nahi hai.");
        }

        $branchId = (int) $settings->fulfil_branch_id;
        $percent = max(0, min(100, (int) $settings->online_stock_percent));

        $rows = DB::table('products as p')
            ->leftJoinSub($this->physicalSub($branchId), 'ph', 'ph.product_id', '=', 'p.id')
            ->where('p.store_id', $storeId)
            ->whereNull('p.deleted_at')
            ->select('p.id', 'p.sale_type', DB::raw('COALESCE(ph.qty, 0) as physical'))
            ->get();

        $now = now();
        $payload = [];

        foreach ($rows as $row) {
            $physical = max(0, (float) $row->physical);
            $share = $physical * $percent / 100;
            $isUnit = in_array($row->sale_type, [null, '', 'unit'], true);

            $payload[] = [
                'product_id' => $row->id,
                'branch_id' => $branchId,
                'allocated_qty' => $isUnit ? floor($share) : floor($share * 100) / 100,
                'allocated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('online_stock_allocations')->upsert(
                $chunk,
                ['product_id', 'branch_id'],
                ['allocated_qty', 'allocated_at', 'updated_at']
            );
        }

        return count($payload);
    }
}