<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\OnlineStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PUBLIC storefront API. Yahan sirf whitelisted fields hi bahar jaate hain
 * (cost_price, created_by, supplier jaisa internal data kabhi nahi).
 * DB::table() isliye use hua hai taaki POS ke models ke global scopes /
 * auth-based store filters is public API ko disturb na karein.
 *
 * LISTING = BRANCH. Ek store ki har branch storefront pe alag dukaan ki tarah
 * dikhti hai (jaise "Adi Tech - Vastrapur"). Stock branch-wise hota hai, isliye
 * order bhi ek branch se hi jayega. Branch ki slug "<naam>-b<branch_id>" hoti hai,
 * aur branch id se hi store aur branch dono mil jaate hain.
 *
 * Price/MRP products table mein nahi, `inventories` (lot-wise) mein hai.
 * Jo lot pehle bikega (bacha hua stock wala, sabse jaldi expiry, phir purana)
 * uska price storefront pe dikhta hai.
 */
class StorefrontController extends Controller
{
    /**
     * true  = jis store ki online_store_settings row nahi hai woh bhi dikhega (default settings ke saath).
     * false = sirf wahi stores dikhengi jinki settings row mein is_online_enabled = 1 hai.
     * Kisi store ko chhupana ho to uski settings row mein is_online_enabled = 0 kar do (ye hamesha maana jata hai).
     */
    private const LIST_ALL_STORES = true;

    private array $colCache = [];

    public function __construct(private OnlineStockService $stock) {}

    // GET /api/public/stores                  -> saari stores ki saari branches (home page)
    // GET /api/public/stores?pincode=380001   -> sirf us pincode pe serve karne wali stores ki branches
    public function stores(Request $request): JsonResponse
    {
        $request->validate(['pincode' => ['nullable', 'digits:6']]);

        // Sirf debugging ke liye: /api/public/stores?debug=1 (sirf jab APP_DEBUG=true ho)
        if ($request->boolean('debug') && config('app.debug')) {
            return response()->json($this->debugReport());
        }

        $pincode = $request->query('pincode');
        $storeIds = null;
        $pinByStore = [];

        if ($pincode) {
            $pinByStore = DB::table('store_service_pincodes')
                ->where('pincode', $pincode)
                ->where('is_active', true)
                ->get()
                ->keyBy('store_id')
                ->all();

            $storeIds = array_keys($pinByStore);
            if (!$storeIds) {
                return response()->json(['data' => []]);
            }
        }

        $rows = $this->listings($storeIds, $pinByStore);

        return response()->json([
            'data' => collect($rows)->map(fn($r) => $this->formatStore($r))->values(),
        ]);
    }

    // GET /api/public/stores/{slug}?pincode=380001 (pincode optional)
    public function show(Request $request, string $slug): JsonResponse
    {
        $store = $this->findStore($slug);

        $pin = null;
        if ($request->filled('pincode')) {
            $request->validate(['pincode' => ['digits:6']]);
            $pin = DB::table('store_service_pincodes')
                ->where('store_id', $store->store_id)
                ->where('pincode', $request->query('pincode'))
                ->where('is_active', true)
                ->first();

            if ($pin) {
                $store->pin_delivery_charge = $pin->delivery_charge;
                $store->pin_min_order = $pin->min_order_amount;
            }
        }

        $data = $this->formatStore($store);
        if ($request->filled('pincode')) {
            $data['serves_pincode'] = (bool) $pin;
        }

        return response()->json(['data' => $data]);
    }

    // GET /api/public/stores/{slug}/categories
    public function categories(string $slug): JsonResponse
    {
        $store = $this->findStore($slug);
        $storeId = (int) $store->store_id;

        $query = DB::table('categories as c')
            ->where(function ($q) use ($storeId) {
                $q->where('c.store_id', $storeId)
                    ->orWhereIn('c.id', function ($sub) use ($storeId) {
                        $sub->select('category_id')->from('products')
                            ->where('store_id', $storeId)
                            ->whereNull('deleted_at')
                            ->whereNotNull('category_id');
                    });
            })
            ->select(
                'c.id',
                'c.name',
                'c.parent_id',
                DB::raw("(select count(*) from products p where p.category_id = c.id and p.store_id = {$storeId} and p.deleted_at is null) as product_count")
            )
            ->orderBy('c.name');

        // categories table mein soft delete ho to deleted waali hide karo
        if ($this->hasCol('categories', 'deleted_at')) {
            $query->whereNull('c.deleted_at');
        }

        return response()->json(['data' => $query->get()]);
    }

    // GET /api/public/stores/{slug}/products?category_id=&search=&page=&per_page=
    public function products(Request $request, string $slug): JsonResponse
    {
        $store = $this->findStore($slug);

        if (!$store->fulfil_branch_id) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $branchId = (int) $store->fulfil_branch_id;

        // Online stock % (store-level setting, default 50)
        $pct = max(0.0, min(100.0, (float) ($store->online_stock_percent ?? 50)));

        // Jo lot sabse pehle bikega: bacha stock wala + expiry nahi nikli, phir jaldi expiry, phir purana
        $lotOrder = "order by (i.qty - i.sold_qty - i.expired_qty > 0 and (i.expiry_date is null or i.expiry_date >= CURDATE())) desc,"
            . " (i.expiry_date is null) asc, i.expiry_date asc, i.id asc limit 1";
        $lotBase = "from inventories i where i.product_id = p.id and i.branch_id = {$branchId} and i.deleted_at is null";

        $query = DB::table('products as p')
            ->leftJoinSub($this->stock->physicalSub($branchId), 'ph', 'ph.product_id', '=', 'p.id')
            ->leftJoin('online_stock_allocations as oa', function ($j) use ($branchId) {
                $j->on('oa.product_id', '=', 'p.id')->where('oa.branch_id', '=', $branchId);
            })
            ->where('p.store_id', $store->store_id)
            ->whereNull('p.deleted_at')
            ->select(
                'p.id',
                'p.name',
                'p.sku',
                'p.barcode',
                'p.image',
                'p.category_id',
                'p.sale_type',
                'p.price_per_kg',
                'p.gst_inclusive',
                DB::raw("(select i.mrp {$lotBase} {$lotOrder}) as lot_mrp"),
                DB::raw("(select i.selling_price {$lotBase} {$lotOrder}) as lot_price"),
                // online qty = min(online bucket, physical), kabhi negative nahi.
                // Is branch ke liye bucket row abhi nahi bani to physical stock ka online % maano.
                DB::raw("GREATEST(LEAST(COALESCE(ph.qty, 0), COALESCE(oa.allocated_qty, ROUND(COALESCE(ph.qty, 0) * {$pct} / 100, 2))), 0) as online_qty")
            );

        if ($categoryId = $request->integer('category_id')) {
            $ids = DB::table('categories')->where('parent_id', $categoryId)->pluck('id')->push($categoryId);
            $query->whereIn('p.category_id', $ids);
        }

        if ($term = trim((string) $request->query('search', ''))) {
            $term = str_replace(['%', '_'], '', $term);
            $query->where(function ($q) use ($term) {
                $q->where('p.name', 'like', "%{$term}%")->orWhere('p.barcode', $term);
            });
        }

        $perPage = max(1, min((int) $request->query('per_page', 24), 60));
        $page = $query->orderBy('p.name')->paginate($perPage);

        $reserved = app(\App\Services\StorefrontCatalog::class)
            ->reservedMap($branchId, $page->getCollection()->pluck('id')->all());

        $items = $page->getCollection()->map(function ($p) use ($reserved) {

            $isUnit = in_array($p->sale_type, [null, '', 'unit'], true);

            $mrp = $p->lot_mrp !== null ? (float) $p->lot_mrp : null;
            $lotPrice = $p->lot_price !== null ? (float) $p->lot_price : null;

            // weight items ka price per kg hota hai, piece items ka lot ka selling price
            $price = $isUnit
                ? ($lotPrice ?? $mrp)
                : ($p->price_per_kg !== null ? (float) $p->price_per_kg : $lotPrice);

            $rawQty = max(0.0, (float) $p->online_qty - (float) ($reserved[$p->id] ?? 0));
            $qty = $isUnit ? (int) floor($rawQty) : floor($rawQty * 100) / 100;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'category_id' => $p->category_id,
                'image' => $p->image
                    ? url('/storage/' . $p->image)
                    : null,

                'image_url' => $p->image
                    ? url('/storage/' . $p->image)
                    : null,
                'sale_type' => $p->sale_type ?: 'unit',
                'price_unit' => $isUnit ? 'piece' : 'kg',
                'mrp' => $mrp,
                'price' => $price,
                'gst_inclusive' => (bool) $p->gst_inclusive,
                'discount_percent' => ($isUnit && $mrp && $price !== null && $mrp > $price)
                    ? (int) round(($mrp - $price) / $mrp * 100)
                    : 0,
                'online_qty' => $qty,
                'in_stock' => $qty > 0,
            ];
        })->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    // Har step pe kitne rows bache: yahin se pata chalta hai ki stores/branches kahan gayab ho rahi hain
    private function debugReport(): array
    {
        $enabled = DB::table('online_store_settings')->where('is_online_enabled', true)->count();

        return [
            'LIST_ALL_STORES' => self::LIST_ALL_STORES,
            'stores_total' => DB::table('stores')->count(),
            'stores_after_deleted_filter' => $this->hasCol('stores', 'deleted_at')
                ? DB::table('stores')->whereNull('deleted_at')->count()
                : 'no deleted_at column',
            'settings_rows' => DB::table('online_store_settings')->count(),
            'settings_enabled' => $enabled,
            'branches_total' => DB::table('branches')->count(),
            'branches_after_filters' => $this->branchQuery()->count(),
            'branches_columns' => Schema::getColumnListing('branches'),
            'sample_branches' => DB::table('branches')->limit(3)->get(),
            'sample_settings' => DB::table('online_store_settings')->limit(3)->get(['id', 'store_id', 'slug', 'is_online_enabled', 'fulfil_branch_id']),
            'final_listings' => count($this->listings(null)),
        ];
    }

    // ---------------------------------------------------------------- listings

    /**
     * Stores ki saari branches ko "listing" bana ke deta hai.
     * $storeIds = null  -> saari stores, warna sirf di hui store ids.
     * $pinByStore       -> [store_id => store_service_pincodes row] (pincode search ke liye)
     */
    private function listings(?array $storeIds, array $pinByStore = []): array
    {
        $stores = DB::table('stores')->select('id', 'name');
        if ($storeIds !== null) {
            $stores->whereIn('id', $storeIds);
        }
        if ($this->hasCol('stores', 'deleted_at')) {
            $stores->whereNull('deleted_at');
        }
        $stores = $stores->orderBy('name')->get();

        if ($stores->isEmpty()) {
            return [];
        }

        $ids = $stores->pluck('id')->all();
        $settings = DB::table('online_store_settings')->whereIn('store_id', $ids)->get()->keyBy('store_id');
        $branches = $this->branchQuery()->whereIn('store_id', $ids)->orderBy('id')->get()->groupBy('store_id');

        $out = [];
        foreach ($stores as $st) {
            $set = $settings->get($st->id);
            if (!$this->isListed($set)) {
                continue;
            }

            $storeBranches = $branches->get($st->id, collect());
            foreach ($storeBranches as $br) {
                $row = $this->makeListing($st, $set, $br, $storeBranches->count());

                if (isset($pinByStore[$st->id])) {
                    $row->pin_delivery_charge = $pinByStore[$st->id]->delivery_charge;
                    $row->pin_min_order = $pinByStore[$st->id]->min_order_amount;
                }

                $out[] = $row;
            }
        }

        usort($out, fn($a, $b) => strcasecmp((string) $a->display_name, (string) $b->display_name));

        return $out;
    }

    // Branches table se sirf wahi columns jo sach mein maujood hain (naam/address/phone ke alag-alag naam ho sakte hain)
    private function branchQuery()
    {
        $pick = fn(array $names) => collect($names)->first(fn($c) => $this->hasCol('branches', $c));

        $q = DB::table('branches')->select('id', 'store_id');

        if ($c = $pick(['name', 'branch_name', 'title'])) {
            $q->addSelect("{$c} as branch_name");
        }
        if ($c = $pick(['address', 'full_address'])) {
            $q->addSelect("{$c} as branch_address");
        }
        if ($c = $pick(['phone', 'mobile', 'contact_number', 'contact_no'])) {
            $q->addSelect("{$c} as branch_phone");
        }

        if ($this->hasCol('branches', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($this->hasCol('branches', 'is_active')) {
            $q->where(fn($w) => $w->where('is_active', 1)->orWhereNull('is_active'));
        }

        return $q;
    }

    // Settings row hai to uska is_online_enabled maano, nahi hai to LIST_ALL_STORES
    private function isListed(?object $set): bool
    {
        return $set ? (bool) $set->is_online_enabled : self::LIST_ALL_STORES;
    }

    private function defaultSettings(int $storeId): object
    {
        return (object) [
            'store_id' => $storeId,
            'slug' => null,
            'display_name' => null,
            'logo' => null,
            'banner' => null,
            'about' => null,
            'phone' => null,
            'address' => null,
            'open_time' => null,
            'close_time' => null,
            'accepts_delivery' => 1,
            'accepts_pickup' => 1,
            'min_order_amount' => 0,
            'delivery_charge' => 0,
            'free_delivery_above' => null,
            'prep_minutes' => 30,
            'online_stock_percent' => 50,
            'is_online_enabled' => 1,
        ];
    }

    // Store ki settings + ek branch = ek storefront listing
    private function makeListing(object $st, ?object $set, object $br, int $branchCount): object
    {
        $s = $set ? clone $set : $this->defaultSettings((int) $st->id);

        $base = ($s->display_name ?? null) ?: $st->name;
        $branchName = trim((string) ($br->branch_name ?? '')) ?: "Branch {$br->id}";

        $s->store_id = (int) $st->id;
        $s->store_name = $base;
        $s->branch_name = $branchName;
        // Ek hi branch ho to sirf store ka naam, warna "Store - Branch"
        $s->display_name = $branchCount > 1 ? "{$base} - {$branchName}" : $base;

        // Branch ka apna address/phone ho to wahi, warna store ka
        if (!empty($br->branch_address)) {
            $s->address = $br->branch_address;
        }
        if (!empty($br->branch_phone)) {
            $s->phone = $br->branch_phone;
        }

        $prefix = ($s->slug ?? null) ?: (Str::slug($base) ?: 'store');
        $s->slug = "{$prefix}-b{$br->id}";
        $s->fulfil_branch_id = (int) $br->id;

        return $s;
    }

    // ---------------------------------------------------------------- helpers

    public function findStore(string $slug): object
    {
        // 1) Branch slug: "<naam>-b<branch_id>"
        if (preg_match('/-b(\d+)$/', $slug, $m)) {
            $row = $this->resolveListing((int) $m[1], null);
            if ($row) {
                return $row;
            }
        }

        // 2) Purana link: settings row ki exact slug -> uski fulfil branch (ya pehli branch)
        $set = DB::table('online_store_settings')
            ->where('slug', $slug)
            ->where('is_online_enabled', true)
            ->first();

        abort_if(!$set, 404, 'Store not found');

        $row = $this->resolveListing($set->fulfil_branch_id ? (int) $set->fulfil_branch_id : null, (int) $set->store_id);
        abort_if(!$row, 404, 'Store not found');

        return $row;
    }

    private function resolveListing(?int $branchId, ?int $storeId): ?object
    {
        $q = $this->branchQuery();
        if ($branchId) {
            $q->where('id', $branchId);
        } elseif ($storeId) {
            $q->where('store_id', $storeId)->orderBy('id');
        } else {
            return null;
        }

        $br = $q->first();
        if (!$br) {
            return null;
        }

        $stq = DB::table('stores')->select('id', 'name')->where('id', $br->store_id);
        if ($this->hasCol('stores', 'deleted_at')) {
            $stq->whereNull('deleted_at');
        }
        $st = $stq->first();
        if (!$st) {
            return null;
        }

        $set = DB::table('online_store_settings')->where('store_id', $st->id)->first();
        if (!$this->isListed($set)) {
            return null;
        }

        $count = $this->branchQuery()->where('store_id', $st->id)->count();

        return $this->makeListing($st, $set, $br, max(1, $count));
    }

    private function hasCol(string $table, string $col): bool
    {
        return $this->colCache["{$table}.{$col}"] ??= Schema::hasColumn($table, $col);
    }

    public function formatStore(object $s): array
    {
        $deliveryCharge = $s->pin_delivery_charge ?? $s->delivery_charge;
        $minOrder = $s->pin_min_order ?? $s->min_order_amount;

        return [
            'slug' => $s->slug,
            'name' => $s->display_name ?: $s->store_name,
            'store_name' => $s->store_name,
            'branch_name' => $s->branch_name ?? null,
            'logo' => $s->logo,
            'banner' => $s->banner,
            'about' => $s->about,
            'phone' => $s->phone,
            'address' => $s->address,
            'open_time' => $s->open_time,
            'close_time' => $s->close_time,
            'is_open_now' => $this->isOpenNow($s->open_time, $s->close_time),
            'accepts_delivery' => (bool) $s->accepts_delivery,
            'accepts_pickup' => (bool) $s->accepts_pickup,
            'min_order_amount' => (float) $minOrder,
            'delivery_charge' => (float) $deliveryCharge,
            'free_delivery_above' => $s->free_delivery_above !== null ? (float) $s->free_delivery_above : null,
            'prep_minutes' => (int) $s->prep_minutes,
        ];
    }

    public function isOpenNow(?string $open, ?string $close): bool
    {
        if (!$open || !$close) {
            return true; // timings set nahi hain to hamesha open maano
        }

        $now = now()->format('H:i:s');

        return $open <= $close
            ? ($now >= $open && $now <= $close)
            : ($now >= $open || $now <= $close); // raat paar karne wale timings
    }
}
