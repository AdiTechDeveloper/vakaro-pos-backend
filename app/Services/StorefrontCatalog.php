<?php

namespace App\Services;

use App\Models\OnlineOrder;
use Illuminate\Support\Facades\DB;

/**
 * Order lete waqt server khud price aur stock nikalta hai (customer ka bheja hua kuch nahi maanta).
 * sellables() mein wahi formula hai jo StorefrontController::products() mein hai,
 * bas yahan sirf diye hue product ids ke liye.
 */
class StorefrontCatalog
{
    public function __construct(private OnlineStockService $stock)
    {
    }

    /**
     * Ek order mein is product ki max quantity: product ka apna limit, nahi to store ka default, nahi to koi limit nahi (null).
     * Piece wale items ke liye poora number (kam se kam 1).
     */
    public static function effectiveLimit($productLimit, $storeLimit, bool $isUnit): ?float
    {
        $v = $productLimit !== null ? (float) $productLimit : ($storeLimit !== null ? (float) $storeLimit : null);
        if ($v === null || $v <= 0) {
            return null;
        }

        return $isUnit ? max(1.0, floor($v)) : round($v, 2);
    }

    /** @return array<int,float>  product_id => us product ka apna limit */
    public function limitMap(int $storeId, array $productIds): array
    {
        if (! $productIds) {
            return [];
        }

        return DB::table('online_product_limits')
            ->where('store_id', $storeId)
            ->whereIn('product_id', $productIds)
            ->pluck('max_qty', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Jo online orders abhi tak deliver nahi hue, unka product-wise qty.
     * Is branch ka online stock itna kam maana jata hai (warna do customers ko same stock bik jayega).
     *
     * @return array<int,float>  product_id => reserved qty
     */
    public function reservedMap(int $branchId, array $productIds): array
    {
        if (! $productIds) {
            return [];
        }

        return DB::table('online_order_items as i')
            ->join('online_orders as o', 'o.id', '=', 'i.online_order_id')
            ->where('o.branch_id', $branchId)
            ->whereIn('o.status', OnlineOrder::PENDING_STATUSES)
            ->whereIn('i.product_id', $productIds)
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id as product_id, SUM(i.qty) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    /**
     * @param  object  $store  StorefrontController::findStore() ka result (listing)
     * @return array<int,array>  product_id => [id, name, price, mrp, price_unit, is_unit, available]
     */
    public function sellables(object $store, array $productIds): array
    {
        if (! $productIds || ! $store->fulfil_branch_id) {
            return [];
        }

        $branchId = (int) $store->fulfil_branch_id;
        $pct = max(0.0, min(100.0, (float) ($store->online_stock_percent ?? 50)));

        $lotOrder = 'order by (i.qty - i.sold_qty - i.expired_qty > 0 and (i.expiry_date is null or i.expiry_date >= CURDATE())) desc,'
            .' (i.expiry_date is null) asc, i.expiry_date asc, i.id asc limit 1';
        $lotBase = "from inventories i where i.product_id = p.id and i.branch_id = {$branchId} and i.deleted_at is null";

        $rows = DB::table('products as p')
            ->leftJoinSub($this->stock->physicalSub($branchId), 'ph', 'ph.product_id', '=', 'p.id')
            ->leftJoin('online_stock_allocations as oa', function ($j) use ($branchId) {
                $j->on('oa.product_id', '=', 'p.id')->where('oa.branch_id', '=', $branchId);
            })
            ->where('p.store_id', $store->store_id)
            ->whereNull('p.deleted_at')
            ->whereIn('p.id', $productIds)
            ->select(
                'p.id',
                'p.name',
                'p.sale_type',
                'p.price_per_kg',
                DB::raw("(select i.mrp {$lotBase} {$lotOrder}) as lot_mrp"),
                DB::raw("(select i.selling_price {$lotBase} {$lotOrder}) as lot_price"),
                DB::raw("GREATEST(LEAST(COALESCE(ph.qty, 0), COALESCE(oa.allocated_qty, ROUND(COALESCE(ph.qty, 0) * {$pct} / 100, 2))), 0) as online_qty")
            )
            ->get();

        $reserved = $this->reservedMap($branchId, $rows->pluck('id')->all());
        $limits = $this->limitMap((int) $store->store_id, $rows->pluck('id')->all());
        $storeLimit = $store->max_qty_per_product ?? null;

        $out = [];
        foreach ($rows as $p) {
            $isUnit = in_array($p->sale_type, [null, '', 'unit'], true);
            $mrp = $p->lot_mrp !== null ? (float) $p->lot_mrp : null;
            $lotPrice = $p->lot_price !== null ? (float) $p->lot_price : null;

            $price = $isUnit
                ? ($lotPrice ?? $mrp)
                : ($p->price_per_kg !== null ? (float) $p->price_per_kg : $lotPrice);

            $qty = max(0.0, (float) $p->online_qty - ($reserved[$p->id] ?? 0.0));
            $qty = $isUnit ? (float) floor($qty) : floor($qty * 100) / 100;

            // Per-order limit: ek baar mein is se zyada nahi (stock zyada ho tab bhi)
            $cap = self::effectiveLimit($limits[$p->id] ?? null, $storeLimit, $isUnit);
            if ($cap !== null) {
                $qty = min($qty, $cap);
            }

            $out[(int) $p->id] = [
                'id' => (int) $p->id,
                'name' => $p->name,
                'price' => $price,
                'mrp' => $mrp,
                'price_unit' => $isUnit ? 'piece' : 'kg',
                'is_unit' => $isUnit,
                'available' => $qty,
                'max_per_order' => $cap,
            ];
        }

        return $out;
    }
}