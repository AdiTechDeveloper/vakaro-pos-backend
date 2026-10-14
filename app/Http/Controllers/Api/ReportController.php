<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\SalesBill;
use App\Models\SalesBillLine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    private function resolveBranchIds(Request $request): array
    {
        $user = Auth::user();

        abort_unless($user && in_array($user->role, ['admin', 'manager'], true), 403, 'Unauthorized');

        if ($user->role === 'admin') {
            $allowed = DB::table('branches')
                ->where('store_id', $user->store_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } else {
            $allowed = $user->branches()
                ->pluck('branches.id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $requested = (int) $request->input('branch_id', 0);

        if ($requested > 0) {
            abort_unless(in_array($requested, $allowed, true), 403, 'Invalid branch access.');

            return [$requested];
        }

        return $allowed;
    }

    private function dateRange(Request $request, ?string $defaultFrom = null, ?string $defaultTo = null): array
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $start = Carbon::parse($request->input('start_date', $defaultFrom ?? now()->startOfMonth()->toDateString()));
        $end = Carbon::parse($request->input('end_date', $defaultTo ?? now()->toDateString()));

        return [$start, $end];
    }

    public function profitLoss(Request $request)
    {
        $branchIds = $this->resolveBranchIds($request);

        $from = $request->filled('from_date')
            ? Carbon::parse($request->from_date)->startOfDay()
            : Carbon::today()->startOfDay();

        $to = $request->filled('to_date')
            ? Carbon::parse($request->to_date)->endOfDay()
            : Carbon::today()->endOfDay();

        $data = DB::table('sales_bill_lines')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('
                COALESCE(SUM(amount), 0) AS total_sales,
                COALESCE(SUM(cogs), 0) AS total_cogs,
                COALESCE(SUM(profit), 0) AS total_profit
            ')
            ->first();

        return response()->json([
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'branch_id' => $request->branch_id ?? 'ALL',
            'sales' => round((float) $data->total_sales, 2),
            'cogs' => round((float) $data->total_cogs, 2),
            'profit' => round((float) $data->total_profit, 2),
            'status' => $data->total_profit >= 0 ? 'profit' : 'loss',
        ]);
    }

    public function topSellingProducts(Request $request)
    {
        $branchIds = $this->resolveBranchIds($request);

        return SalesBillLine::query()
            ->select([
                'product_id',
                DB::raw('SUM(qty) as total_qty'),
                DB::raw('SUM(amount) as total_sales'),
                DB::raw('SUM(profit) as total_profit'),
            ])
            ->with('product:id,name,sku')
            ->whereIn('branch_id', $branchIds)
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();
    }

    public function stockSummary(Request $request)
    {
        $user = Auth::user();
        $branchIds = $this->resolveBranchIds($request);
        $storeId = (int) $user->store_id;

        if ($user->role === 'manager' && empty($branchIds)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No branch assigned to this manager',
                'data' => [],
            ], 403);
        }

        [$startC, $endC] = $this->dateRange(
            $request,
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString()
        );

        $startDate = $startC->toDateString();
        $endDate = $endC->toDateString();
        $lastStart = $startC->copy()->subMonth()->startOfMonth()->toDateString();
        $lastEnd = $startC->copy()->subMonth()->endOfMonth()->toDateString();

        $perPage = (int) ($request->per_page ?? 10);

        $idList = ! empty($branchIds) ? implode(',', $branchIds) : '0';

        $branchWherePb = "AND pb.store_id = {$storeId} AND pb.branch_id IN ({$idList})";
        $branchWhereSb = "AND sb.store_id = {$storeId} AND sb.branch_id IN ({$idList})";
        $branchWhereInv = "WHERE branch_id IN ({$idList})";

        $query = DB::table('products as p')
            ->selectRaw('
                p.id as product_id,
                p.name as product_name,
                p.barcode as barcode,
                COALESCE(pur_before.total_purchase, 0) - COALESCE(sal_before.total_sale, 0) as opening_stock,
                COALESCE(pur_month.total_purchase, 0) as purchased,
                COALESCE(sal_month.total_sale, 0) as sold,
                COALESCE(inv.stock, 0) as closing_stock,
                COALESCE(sal_last.total_sale, 0) as last_month_sold
            ')
            ->where('p.store_id', $storeId)
            ->when($request->search, fn ($q) => $q->where('p.name', 'like', "%{$request->search}%"))
            ->when($request->product_id, fn ($q) => $q->where('p.id', (int) $request->product_id));

        // Specific branch (manager ya admin ne branch choose ki) ho to sirf us branch ka stock wale products
        if ($user->role === 'manager' || (int) $request->input('branch_id', 0) > 0) {
            $query->whereExists(function ($q) use ($branchIds) {
                $q->select(DB::raw(1))
                    ->from('inventories as i')
                    ->whereColumn('i.product_id', 'p.id')
                    ->whereIn('i.branch_id', $branchIds);
            });
        }

        $query->leftJoin(DB::raw("(
            SELECT pl.product_id, SUM(pl.qty + pl.free_qty) AS total_purchase
            FROM purchase_lines pl
            JOIN purchase_bills pb ON pb.id = pl.purchase_bill_id
            WHERE pb.bill_date < '{$startDate}' {$branchWherePb}
            GROUP BY pl.product_id
        ) AS pur_before"), 'pur_before.product_id', '=', 'p.id');

        $query->leftJoin(DB::raw("(
            SELECT sl.product_id, SUM(sl.qty) AS total_sale
            FROM sales_bill_lines sl
            JOIN sales_bills sb ON sb.id = sl.sales_bill_id
            WHERE sb.created_at < '{$startDate}' {$branchWhereSb}
            GROUP BY sl.product_id
        ) AS sal_before"), 'sal_before.product_id', '=', 'p.id');

        $query->leftJoin(DB::raw("(
            SELECT pl.product_id, SUM(pl.qty + pl.free_qty) AS total_purchase
            FROM purchase_lines pl
            JOIN purchase_bills pb ON pb.id = pl.purchase_bill_id
            WHERE pb.bill_date BETWEEN '{$startDate}' AND '{$endDate}' {$branchWherePb}
            GROUP BY pl.product_id
        ) AS pur_month"), 'pur_month.product_id', '=', 'p.id');

        $query->leftJoin(DB::raw("(
            SELECT sl.product_id, SUM(sl.qty) AS total_sale
            FROM sales_bill_lines sl
            JOIN sales_bills sb ON sb.id = sl.sales_bill_id
            WHERE sb.created_at BETWEEN '{$startDate} 00:00:00' AND '{$endDate} 23:59:59' {$branchWhereSb}
            GROUP BY sl.product_id
        ) AS sal_month"), 'sal_month.product_id', '=', 'p.id');

        $query->leftJoin(DB::raw("(
            SELECT sl.product_id, SUM(sl.qty) AS total_sale
            FROM sales_bill_lines sl
            JOIN sales_bills sb ON sb.id = sl.sales_bill_id
            WHERE sb.created_at BETWEEN '{$lastStart} 00:00:00' AND '{$lastEnd} 23:59:59' {$branchWhereSb}
            GROUP BY sl.product_id
        ) AS sal_last"), 'sal_last.product_id', '=', 'p.id');

        $query->leftJoin(DB::raw("(
            SELECT product_id, SUM(qty - sold_qty - expired_qty) AS stock
            FROM inventories
            {$branchWhereInv}
            GROUP BY product_id
        ) AS inv"), 'inv.product_id', '=', 'p.id');

        $products = $query->paginate($perPage)->appends($request->all());

        $data = collect($products->items())->map(function ($item) {
            $totalAvailable = $item->opening_stock + $item->purchased;
            $percentage = $totalAvailable > 0
                ? round(($item->sold / $totalAvailable) * 100, 2)
                : 0;

            $trend = match (true) {
                $item->last_month_sold == 0 && $item->sold > 0 => 'new',
                $item->sold > $item->last_month_sold => 'up',
                $item->sold < $item->last_month_sold => 'down',
                default => 'same',
            };

            $stockHealth = match (true) {
                $item->closing_stock <= 0 => 'out_of_stock',
                $percentage > 70 => 'fast_moving',
                $percentage < 10 => 'slow_moving',
                default => 'normal',
            };

            return [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'barcode' => $item->barcode,
                'opening_stock' => (float) $item->opening_stock,
                'purchased' => (float) $item->purchased,
                'sold' => (float) $item->sold,
                'closing_stock' => (float) $item->closing_stock,
                'sales_percentage' => $percentage,
                'trend' => $trend,
                'stock_health' => $stockHealth,
                'dead_stock' => ($item->sold == 0 && $item->closing_stock > 0),
            ];
        });

        return response()->json([
            'status' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function purchaseSummary(Request $request)
    {
        $user = Auth::user();
        $storeId = (int) $user->store_id;
        $branchIds = $this->resolveBranchIds($request);

        if ($user->role === 'manager' && empty($branchIds)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No branch assigned to this manager',
                'data' => [],
            ], 403);
        }

        [$startC, $endC] = $this->dateRange($request);
        $start = $startC->toDateString();
        $end = $endC->toDateString();

        $groupBy = $request->group_by ?? 'date';
        $supplierId = $request->supplier_id;
        $brandId = $request->brand_id;
        $productId = $request->product_id;
        $includeBills = $request->include_bills;

        $query = DB::table('purchase_bills as pb')
            ->join('purchase_lines as pl', 'pl.purchase_bill_id', '=', 'pb.id')
            ->leftJoin('products as p', 'p.id', '=', 'pl.product_id')
            ->leftJoin('gst_rates as g', 'g.id', '=', 'pl.gst_rate_id')
            ->where('pb.store_id', $storeId)
            ->whereIn('pb.branch_id', $branchIds)
            ->whereDate('pb.bill_date', '>=', $start)
            ->whereDate('pb.bill_date', '<=', $end);

        if (! empty($supplierId)) {
            $query->where('pb.supplier_id', $supplierId);
        }
        if (! empty($brandId)) {
            $query->where('p.brand_id', $brandId);
        }
        if (! empty($productId)) {
            $query->where('pl.product_id', $productId);
        }

        $labelIdCol = null;
        switch ($groupBy) {
            case 'brand':
                $query->leftJoin('brands as b', 'b.id', '=', 'p.brand_id');
                $labelCol = 'b.name';
                $labelIdCol = 'b.id';
                break;
            case 'product':
                $labelCol = 'p.name';
                $labelIdCol = 'p.id';
                break;
            case 'supplier':
                $query->leftJoin('suppliers as s', 's.id', '=', 'pb.supplier_id');
                $labelCol = 's.name';
                $labelIdCol = 'pb.supplier_id';
                break;
            case 'gst_rate':
                $labelCol = 'g.rate';
                $labelIdCol = 'g.id';
                break;
            default:
                $labelCol = 'pb.bill_date';
                $labelIdCol = null;
        }

        $report = $query
            ->selectRaw(($labelIdCol ? "$labelIdCol as label_id," : '')."COALESCE($labelCol, 'N/A') as label")
            ->selectRaw('SUM(pl.qty + COALESCE(pl.free_qty, 0)) as total_qty')
            ->selectRaw('ROUND(SUM(pl.taxable_value), 2) as total_taxable')
            ->selectRaw('ROUND(SUM(pl.cgst + pl.sgst + pl.igst), 2) as total_gst')
            ->selectRaw('ROUND(SUM(pl.taxable_value + pl.cgst + pl.sgst + pl.igst), 2) as total_amount')
            ->when($labelIdCol, function ($q) use ($labelIdCol, $labelCol) {
                $q->groupBy($labelIdCol, $labelCol);
            }, function ($q) use ($labelCol) {
                $q->groupBy($labelCol);
            })
            ->orderBy('label', 'asc')
            ->get();

        $taxSlabs = DB::table('purchase_lines as pl')
            ->join('purchase_bills as pb', 'pb.id', '=', 'pl.purchase_bill_id')
            ->leftJoin('gst_rates as g', 'g.id', '=', 'pl.gst_rate_id')
            ->leftJoin('products as p', 'p.id', '=', 'pl.product_id')
            ->where('pb.store_id', $storeId)
            ->whereIn('pb.branch_id', $branchIds)
            ->whereDate('pb.bill_date', '>=', $start)
            ->whereDate('pb.bill_date', '<=', $end)
            ->when($supplierId, fn ($q) => $q->where('pb.supplier_id', $supplierId))
            ->when($brandId, fn ($q) => $q->where('p.brand_id', $brandId))
            ->when($productId, fn ($q) => $q->where('pl.product_id', $productId))
            ->selectRaw('COALESCE(g.rate, 0) as slab_name, g.rate')
            ->selectRaw('ROUND(SUM(pl.taxable_value), 2) as taxable')
            ->selectRaw('ROUND(SUM(pl.cgst + pl.sgst + pl.igst), 2) as gst')
            ->groupBy('g.id', 'g.rate')
            ->get();

        $bills = [];
        if ($includeBills) {
            $bills = DB::table('purchase_bills as pb')
                ->leftJoin('suppliers as s', 's.id', '=', 'pb.supplier_id')
                ->where('pb.store_id', $storeId)
                ->whereIn('pb.branch_id', $branchIds)
                ->whereDate('pb.bill_date', '>=', $start)
                ->whereDate('pb.bill_date', '<=', $end)
                ->when($supplierId, fn ($q) => $q->where('pb.supplier_id', $supplierId))
                ->when($productId || $brandId, function ($q) use ($productId, $brandId) {
                    $q->whereExists(function ($sq) use ($productId, $brandId) {
                        $sq->select(DB::raw(1))
                            ->from('purchase_lines as pl_inner')
                            ->leftJoin('products as p_inner', 'p_inner.id', '=', 'pl_inner.product_id')
                            ->whereColumn('pl_inner.purchase_bill_id', 'pb.id')
                            ->when($productId, fn ($inner) => $inner->where('pl_inner.product_id', $productId))
                            ->when($brandId, fn ($inner) => $inner->where('p_inner.brand_id', $brandId));
                    });
                })
                ->select('pb.id', 'pb.bill_no', 'pb.bill_date', 'pb.taxable_value', 'pb.total_tax', 'pb.total_amount', 's.name as supplier_name')
                ->orderBy('pb.bill_date', 'desc')
                ->limit(500)
                ->get();
        }

        return response()->json([
            'success' => true,
            'meta' => [
                'start_date' => $start,
                'end_date' => $end,
                'group_by' => $groupBy,
                'branch_id' => $request->input('branch_id'),
            ],
            'report' => $report,
            'tax_slabs' => $taxSlabs,
            'bills' => $bills,
        ]);
    }

    public function salesSummary(Request $request)
    {
        $user = Auth::user();
        $branchIds = $this->resolveBranchIds($request);

        $query = SalesBill::from('sales_bills as sb')
            ->where('sb.store_id', $user->store_id)
            ->whereIn('sb.branch_id', $branchIds);

        if ($request->start_date && $request->end_date) {
            [$startC, $endC] = $this->dateRange($request);

            $query->whereBetween('sb.created_at', [
                $startC->toDateString().' 00:00:00',
                $endC->toDateString().' 23:59:59',
            ]);
        }

        $data = $query
            ->selectRaw('DATE(sb.created_at) as date')
            ->selectRaw('COUNT(sb.id) as bills')
            ->selectRaw('SUM(sb.subtotal) as total_taxable')
            ->selectRaw('SUM(sb.total_gst) as total_gst')
            ->selectRaw('SUM(sb.total_amount) as total_amount')
            ->selectRaw('SUM(sb.total_saved) as total_discount')
            ->selectRaw('SUM(sb.total_cogs) as total_cost')
            ->selectRaw('SUM(sb.total_profit) as total_profit')
            ->groupBy(DB::raw('DATE(sb.created_at)'))
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'report' => $data,
        ]);
    }

    public function salesAnalytics(Request $request)
    {
        $user = Auth::user();
        $storeId = (int) $user->store_id;
        $branchIds = $this->resolveBranchIds($request);

        [$startC, $endC] = $this->dateRange($request);
        $start = $startC->toDateString().' 00:00:00';
        $end = $endC->toDateString().' 23:59:59';

        $branchId = $request->input('branch_id');

        // Reusable base queries: store + branch scope hamesha lagega
        $bills = fn () => DB::table('sales_bills')
            ->where('store_id', $storeId)
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('created_at', [$start, $end]);

        $lines = fn () => DB::table('sales_bill_lines as l')
            ->join('sales_bills as b', 'b.id', '=', 'l.sales_bill_id')
            ->where('b.store_id', $storeId)
            ->whereIn('b.branch_id', $branchIds)
            ->whereBetween('b.created_at', [$start, $end]);

        // DAILY SALES SUMMARY
        $daily = $bills()
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('COUNT(id) as bills')
            ->selectRaw('SUM(subtotal) as total_taxable')
            ->selectRaw('SUM(total_gst) as total_gst')
            ->selectRaw('SUM(total_amount) as total_amount')
            ->selectRaw('SUM(total_saved) as total_discount')
            ->selectRaw('SUM(total_cogs) as total_cost')
            ->selectRaw('SUM(total_profit) as total_profit')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        // HOURLY SALES HEATMAP
        $hourly = $bills()
            ->selectRaw('HOUR(created_at) as hour')
            ->selectRaw('SUM(total_amount) as sales')
            ->groupBy('hour')
            ->orderBy('hour')
            ->pluck('sales', 'hour');

        // TOP 10 BEST-SELLING PRODUCTS
        $topProducts = $lines()
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->select('p.id', 'p.name')
            ->selectRaw('SUM(l.qty) as total_qty')
            ->selectRaw('SUM(l.amount) as total_amount')
            ->groupBy('p.id', 'p.name')
            ->orderBy('total_qty', 'desc')
            ->limit(10)
            ->get();

        // SLOW MOVING PRODUCTS (bottom 10)
        $slowProducts = $lines()
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->select('p.id', 'p.name')
            ->selectRaw('SUM(l.qty) as sold_qty')
            ->groupBy('p.id', 'p.name')
            ->orderBy('sold_qty', 'asc')
            ->limit(10)
            ->get();

        // PAYMENT METHOD BREAKDOWN
        $payments = DB::table('sales_bill_payments as sbp')
            ->join('sales_bills as b', 'b.id', '=', 'sbp.sales_bill_id')
            ->where('b.store_id', $storeId)
            ->whereIn('b.branch_id', $branchIds)
            ->where('sbp.status', 'success')
            ->whereBetween('sbp.created_at', [$start, $end])
            ->selectRaw('sbp.method, SUM(sbp.amount) as total')
            ->groupBy('sbp.method')
            ->pluck('total', 'method');

        // HIGHEST SALE DAY
        $highestDay = $bills()
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('SUM(total_amount) as sale')
            ->groupBy('date')
            ->orderBy('sale', 'desc')
            ->first();

        // LOWEST SALE DAY
        $lowestDay = $bills()
            ->whereDate('created_at', '<', Carbon::today())
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('SUM(total_amount) as sale')
            ->groupBy('date')
            ->orderBy('sale', 'asc')
            ->first();

        // BRANCHWISE PERFORMANCE
        $branchPerformance = DB::table('sales_bills as b')
            ->join('branches as br', 'br.id', '=', 'b.branch_id')
            ->where('b.store_id', $storeId)
            ->whereIn('b.branch_id', $branchIds)
            ->whereBetween('b.created_at', [$start, $end])
            ->select('br.name as branch')
            ->selectRaw('COUNT(b.id) as bills')
            ->selectRaw('SUM(b.total_amount) as sales')
            ->selectRaw('SUM(b.total_profit) as profit')
            ->groupBy('br.id', 'br.name')
            ->orderBy('sales', 'desc')
            ->get();

        // BRAND / CATEGORY SALES
        $brandSales = $lines()
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('brands as br', 'br.id', '=', 'p.brand_id')
            ->select('br.id', 'br.name')
            ->selectRaw('SUM(l.qty) as qty')
            ->selectRaw('SUM(l.amount) as amount')
            ->groupBy('br.id', 'br.name')
            ->orderBy('amount', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'meta' => [
                'start_date' => $startC->toDateString(),
                'end_date' => $endC->toDateString(),
                'branch_id' => $branchId,
            ],
            'summary' => $daily,
            'hourly_heatmap' => $hourly,
            'top_products' => $topProducts,
            'slow_products' => $slowProducts,
            'payment_methods' => $payments,
            'sales_extremes' => [
                'highest_day' => $highestDay ? [
                    'date' => $highestDay->date,
                    'sales' => (float) $highestDay->sale,
                ] : null,
                'lowest_day' => $lowestDay ? [
                    'date' => $lowestDay->date,
                    'sales' => (float) $lowestDay->sale,
                ] : null,
            ],
            'highest_sale_day' => $highestDay,
            'lowest_sale_day' => $lowestDay,
            'branch_performance' => $branchPerformance,
            'brand_sales' => $brandSales,
        ]);
    }

    public function getExpiryReport(Request $request)
    {
        try {
            $branchIds = $this->resolveBranchIds($request);

            $expiryData = Inventory::with(['product:id,name'])
                ->whereIn('branch_id', $branchIds)
                ->whereNotNull('expiry_date')
                ->whereColumn('qty', '>', 'sold_qty')
                ->orderBy('expiry_date', 'asc')
                ->get([
                    'id',
                    'product_id',
                    'branch_id',
                    'batch_no',
                    'expiry_date',
                    'qty',
                    'sold_qty',
                ]);

            $formattedData = $expiryData->map(function ($item) {
                return [
                    'inventory_id' => $item->id,
                    'product_name' => $item->product->name ?? 'Unknown Product',
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'remaining_qty' => $item->qty - $item->sold_qty,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Expiry report retrieved successfully.',
                'data' => $formattedData,
            ], 200);

        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch expiry data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
