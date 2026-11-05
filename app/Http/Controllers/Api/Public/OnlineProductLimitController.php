<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Concerns\ResolvesOnlineScope;
use App\Http\Controllers\Controller;
use App\Models\OnlineProductLimit;
use App\Models\OnlineStoreSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POS Admin: har product ki "ek order mein max quantity". Sirf apni store ke products (store_id se bandha).
 */
class OnlineProductLimitController extends Controller
{
    use ResolvesOnlineScope;

    // GET /api/online/product-limits?search=&only_limited=0|1&page=&per_page=
    public function index(Request $request): JsonResponse
    {
        [$storeId] = $this->onlineScope($request, true);

        $q = DB::table('products as p')
            ->leftJoin('online_product_limits as l', function ($j) use ($storeId) {
                $j->on('l.product_id', '=', 'p.id')->where('l.store_id', '=', $storeId);
            })
            ->where('p.store_id', $storeId)
            ->whereNull('p.deleted_at');

        if ($term = trim((string) $request->query('search', ''))) {
            $term = str_replace(['%', '_'], '', $term);
            $q->where(function ($w) use ($term) {
                $w->where('p.name', 'like', "%{$term}%")->orWhere('p.sku', 'like', "%{$term}%")->orWhere('p.barcode', $term);
            });
        }
        if ($request->boolean('only_limited')) {
            $q->whereNotNull('l.max_qty');
        }

        $perPage = max(5, min((int) $request->query('per_page', 15), 50));
        $page = $q->orderBy('p.name')->select('p.id', 'p.name', 'p.sku', 'p.sale_type', 'l.max_qty')->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => $page->getCollection()->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'sku' => $r->sku,
                'is_weight' => ! in_array($r->sale_type, [null, '', 'unit'], true),
                'max_qty' => $r->max_qty !== null ? (float) $r->max_qty : null,
            ])->values(),
            'default_limit' => OnlineStoreSetting::where('store_id', $storeId)->value('max_qty_per_product'),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    // PUT /api/online/product-limits/{productId}   { max_qty: 20 | null }   (null = limit hata do, store default chalega)
    public function update(Request $request, int $productId): JsonResponse
    {
        [$storeId] = $this->onlineScope($request, true);

        $data = $request->validate([
            'max_qty' => ['present', 'nullable', 'numeric', 'min:0.01', 'max:100000'],
        ]);

        $product = DB::table('products')
            ->where('id', $productId)->where('store_id', $storeId)->whereNull('deleted_at')
            ->first(['id', 'sale_type']);
        abort_if(! $product, 404, 'Product not found.');

        $max = $data['max_qty'];

        if ($max === null) {
            OnlineProductLimit::where('store_id', $storeId)->where('product_id', $productId)->delete();

            return response()->json(['status' => true, 'data' => ['id' => $productId, 'max_qty' => null]]);
        }

        $isUnit = in_array($product->sale_type, [null, '', 'unit'], true);
        if ($isUnit && abs((float) $max - round((float) $max)) > 1e-9) {
            return response()->json(['status' => false, 'message' => 'Use a whole number for items sold by piece.'], 422);
        }

        OnlineProductLimit::updateOrCreate(
            ['store_id' => $storeId, 'product_id' => $productId],
            ['max_qty' => round((float) $max, 2)]
        );

        return response()->json(['status' => true, 'data' => ['id' => $productId, 'max_qty' => round((float) $max, 2)]]);
    }
}