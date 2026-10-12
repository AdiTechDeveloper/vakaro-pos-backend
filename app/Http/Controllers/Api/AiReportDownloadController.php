<?php

namespace App\Http\Controllers\Api;

use App\Exports\GenericReportExport;
use App\Http\Controllers\Controller;
use App\Services\SalesReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Generic PDF/Excel download for AI assistant reports
 * (stock, low stock, out of stock, supplier dues, top selling, purchase suggestions).
 * Sales report keeps using AiSalesReportDownloadController.
 */
class AiReportDownloadController extends Controller
{
    public function download(Request $request, SalesReportService $salesReportService)
    {
        $validated = $request->validate([
            'report_type' => [
                'required',
                'in:inventory,low_stock,out_of_stock,supplier_dues,top_selling,purchase_suggestions,product_profit,branch_comparison,purchase_report',
            ],
            'format' => ['required', 'in:pdf,excel'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'min:0'],
            'options' => ['nullable', 'array'],
            'options.threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'options.limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'options.cover_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $user = $request->user();

        if (!in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'message' => 'You are not authorized to download reports.',
            ], 403);
        }

        $branchId = $this->resolveAuthorizedBranch($user, $validated['branch_id'] ?? null);
        $options = $validated['options'] ?? [];
        $type = $validated['report_type'];

        $report = match ($type) {
            'inventory' => $this->inventoryReport($user, $branchId),
            'low_stock' => $this->stockLevelReport($user, $branchId, false, (int) ($options['threshold'] ?? 10)),
            'out_of_stock' => $this->stockLevelReport($user, $branchId, true, 0),
            'supplier_dues' => $this->supplierDuesReport($user, $branchId),
            'top_selling' => $this->topSellingReport(
                $salesReportService,
                $user,
                $branchId,
                $validated['date_from'],
                $validated['date_to'],
                (int) ($options['limit'] ?? 20)
            ),
            'purchase_suggestions' => $this->purchaseSuggestionsReport(
                $salesReportService,
                $user,
                $branchId,
                (int) ($options['cover_days'] ?? 7)
            ),
            'product_profit' => $this->productProfitReport(
                $salesReportService,
                $user,
                $branchId,
                $validated['date_from'],
                $validated['date_to']
            ),
            'branch_comparison' => $this->branchComparisonReport(
                $salesReportService,
                $user,
                $validated['date_from'],
                $validated['date_to']
            ),
            'purchase_report' => $this->purchaseReport(
                $user,
                $branchId,
                $validated['date_from'],
                $validated['date_to']
            ),
        };

        if (isset($report['error'])) {
            return response()->json(['message' => $report['error']], 422);
        }

        $filename = Str::slug($type) . '-' . $validated['date_from'] . '-to-' . $validated['date_to'];

        if ($validated['format'] === 'pdf') {
            $pdf = Pdf::loadView('reports.ai-generic-pdf', [
                'title' => $report['title'],
                'subtitle' => $report['subtitle'] ?? null,
                'headings' => $report['headings'],
                'rows' => $report['rows'],
                'summary' => $report['summary'] ?? [],
            ])->setPaper('a4', count($report['headings']) > 6 ? 'landscape' : 'portrait');

            return $pdf->download($filename . '.pdf');
        }

        return Excel::download(
            new GenericReportExport($report['headings'], $report['rows'], $report['title']),
            $filename . '.xlsx'
        );
    }

    /* ───────────── Report builders ───────────── */

    private function inventoryReport($user, ?int $branchId): array
    {
        $query = DB::table('inventories as i')
            ->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('b.store_id', $user->store_id)
            ->whereNull('i.deleted_at');

        if ($branchId !== null) {
            $query->where('i.branch_id', $branchId);
        }

        $data = $query
            ->selectRaw('
                p.name as product_name,
                p.sku as sku,
                COALESCE(MAX(i.selling_price), 0) as selling_price,
                SUM(i.qty) as total_qty,
                SUM(i.sold_qty) as sold_qty,
                SUM(COALESCE(i.expired_qty, 0)) as expired_qty,
                SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) as available_qty
            ')
            ->groupBy('p.id', 'p.name', 'p.sku')
            ->orderBy('p.name')
            ->limit(5000)
            ->get();

        $rows = $data->map(fn ($r) => [
            $r->product_name,
            $r->sku,
            (float) $r->total_qty,
            (float) $r->sold_qty,
            (float) $r->expired_qty,
            (float) $r->available_qty,
            round((float) $r->selling_price, 2),
        ])->all();

        return [
            'title' => 'Stock Report',
            'subtitle' => $this->branchLabel($user, $branchId),
            'headings' => ['Product', 'SKU', 'Total Qty', 'Sold Qty', 'Expired Qty', 'Available Qty', 'Selling Price'],
            'rows' => $rows,
            'summary' => [
                'Total products' => count($rows),
                'Total available qty' => round((float) $data->sum('available_qty'), 2),
            ],
        ];
    }

    private function stockLevelReport($user, ?int $branchId, bool $outOfStock, int $threshold): array
    {
        $threshold = max(0, min($threshold, 100000));
        $expr = 'SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0))';

        $query = DB::table('inventories as i')
            ->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('b.store_id', $user->store_id)
            ->whereNull('i.deleted_at');

        if ($branchId !== null) {
            $query->where('i.branch_id', $branchId);
        }

        $query->selectRaw("b.name as branch_name, p.name as product_name, {$expr} as available_qty")
            ->groupBy('i.branch_id', 'b.name', 'p.id', 'p.name');

        if ($outOfStock) {
            $query->havingRaw("{$expr} <= 0");
        } else {
            $query->havingRaw("{$expr} > 0")
                ->havingRaw("{$expr} <= ?", [$threshold]);
        }

        $data = $query->orderBy('available_qty')->limit(2000)->get();

        $rows = $data->map(fn ($r) => [
            $r->branch_name,
            $r->product_name,
            (float) $r->available_qty,
        ])->all();

        return [
            'title' => $outOfStock ? 'Out of Stock Report' : 'Low Stock Report',
            'subtitle' => $this->branchLabel($user, $branchId)
                . ($outOfStock ? '' : " | Threshold: {$threshold}"),
            'headings' => ['Branch', 'Product', 'Available Qty'],
            'rows' => $rows,
            'summary' => ['Total products' => count($rows)],
        ];
    }

    private function supplierDuesReport($user, ?int $branchId): array
    {
        $bills = DB::table('purchase_bills as pb')
            ->join('suppliers as s', 's.id', '=', 'pb.supplier_id')
            ->where('pb.store_id', $user->store_id)
            ->whereNull('pb.deleted_at');

        $payments = DB::table('purchase_payments as pp')
            ->join('purchase_bills as pb', 'pb.id', '=', 'pp.purchase_bill_id')
            ->where('pb.store_id', $user->store_id)
            ->whereNull('pb.deleted_at')
            ->where('pp.status', 'success');

        if ($branchId !== null) {
            $bills->where('pb.branch_id', $branchId);
            $payments->where('pb.branch_id', $branchId);
        }

        $billRows = $bills
            ->selectRaw('s.id as supplier_id, s.name as supplier_name, COUNT(pb.id) as bill_count, SUM(pb.total_amount) as total_purchase')
            ->groupBy('s.id', 's.name')
            ->orderBy('s.name')
            ->get();

        $paidBySupplier = $payments
            ->selectRaw('pb.supplier_id, SUM(pp.amount) as paid')
            ->groupBy('pb.supplier_id')
            ->pluck('paid', 'supplier_id');

        $totalDue = 0.0;
        $rows = $billRows->map(function ($r) use ($paidBySupplier, &$totalDue) {
            $total = (float) $r->total_purchase;
            $paid = (float) ($paidBySupplier[$r->supplier_id] ?? 0);
            $due = max(0, $total - $paid);
            $totalDue += $due;

            return [
                $r->supplier_name,
                (int) $r->bill_count,
                round($total, 2),
                round($paid, 2),
                round($due, 2),
            ];
        })->all();

        return [
            'title' => 'Supplier Dues Report',
            'subtitle' => $this->branchLabel($user, $branchId),
            'headings' => ['Supplier', 'Bills', 'Total Purchase', 'Paid', 'Outstanding Due'],
            'rows' => $rows,
            'summary' => [
                'Suppliers' => count($rows),
                'Total outstanding due' => round($totalDue, 2),
            ],
        ];
    }

    private function topSellingReport(
        SalesReportService $salesReportService,
        $user,
        ?int $branchId,
        string $from,
        string $to,
        int $limit
    ): array {
        $limit = max(1, min($limit, 100));

        $filters = $salesReportService->resolveFilters([
            'date_range' => 'custom',
            'date_from' => $from,
            'date_to' => $to,
            'bill_status' => 'all',
            'store_id' => $user->store_id,
            'branch_id' => $branchId,
        ]);

        $performance = $salesReportService->getProductPerformance($filters);

        $rows = collect($performance['rows'])
            ->sortByDesc('qty_sold')
            ->take($limit)
            ->values()
            ->map(fn ($p, $i) => [
                $i + 1,
                $p->product_name,
                (float) $p->qty_sold,
                round((float) $p->net_revenue, 2),
                round((float) $p->total_profit, 2),
            ])->all();

        return [
            'title' => 'Top Selling Products Report',
            'subtitle' => "{$from} to {$to} | " . $this->branchLabel($user, $branchId),
            'headings' => ['Rank', 'Product', 'Qty Sold', 'Revenue', 'Profit'],
            'rows' => $rows,
            'summary' => ['Products listed' => count($rows)],
        ];
    }

    private function purchaseSuggestionsReport(
        SalesReportService $salesReportService,
        $user,
        ?int $branchId,
        int $coverDays
    ): array {
        if (!$branchId) {
            return ['error' => 'Purchase suggestions report ke liye ek branch select karein.'];
        }

        $coverDays = max(1, min($coverDays, 30));

        $filters = $salesReportService->resolveFilters([
            'date_range' => 'custom',
            'date_from' => Carbon::today()->subDays(29)->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'bill_status' => 'all',
            'store_id' => $user->store_id,
            'branch_id' => $branchId,
        ]);

        $salesByProduct = collect($salesReportService->getProductPerformance($filters)['rows'])
            ->keyBy('product_id');

        $stockRows = DB::table('inventories as i')
            ->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('b.store_id', $user->store_id)
            ->where('i.branch_id', $branchId)
            ->whereNull('i.deleted_at')
            ->selectRaw('i.product_id, p.name as product_name, SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) as available_qty')
            ->groupBy('i.product_id', 'p.name')
            ->get();

        $rows = $stockRows->map(function ($stock) use ($salesByProduct, $coverDays) {
            $sold = (float) ($salesByProduct->get($stock->product_id)->qty_sold ?? 0);
            $available = max(0, (float) $stock->available_qty);
            $daily = $sold / 30;
            $reorder = max(0, (int) ceil($daily * $coverDays) - $available);

            return [
                'name' => $stock->product_name,
                'sold' => $sold,
                'available' => $available,
                'daily' => round($daily, 2),
                'reorder' => $reorder,
            ];
        })
            ->filter(fn ($r) => $r['reorder'] > 0)
            ->sortByDesc('reorder')
            ->values()
            ->map(fn ($r) => [$r['name'], $r['sold'], $r['available'], $r['daily'], $r['reorder']])
            ->all();

        return [
            'title' => 'Purchase Suggestions Report',
            'subtitle' => $this->branchLabel($user, $branchId)
                . " | Based on last 30 days sales, cover {$coverDays} days (estimates)",
            'headings' => ['Product', 'Sold (Last 30 Days)', 'Available Qty', 'Avg Daily Demand', 'Suggested Reorder Qty'],
            'rows' => $rows,
            'summary' => ['Products to reorder' => count($rows)],
        ];
    }

    private function productProfitReport(
        SalesReportService $salesReportService,
        $user,
        ?int $branchId,
        string $from,
        string $to
    ): array {
        $filters = $salesReportService->resolveFilters([
            'date_range' => 'custom',
            'date_from' => $from,
            'date_to' => $to,
            'bill_status' => 'all',
            'store_id' => $user->store_id,
            'branch_id' => $branchId,
        ]);

        $products = collect($salesReportService->getProductPerformance($filters)['rows'])
            ->sortByDesc('total_profit')
            ->values();

        $rows = $products->take(1000)->map(function ($p) {
            $revenue = (float) $p->net_revenue;
            $profit = (float) $p->total_profit;

            return [
                $p->product_name,
                (float) $p->qty_sold,
                round($revenue, 2),
                round($profit, 2),
                $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
            ];
        })->all();

        return [
            'title' => 'Profit Report (Product-wise)',
            'subtitle' => "{$from} to {$to} | " . $this->branchLabel($user, $branchId),
            'headings' => ['Product', 'Qty Sold', 'Revenue', 'Profit', 'Margin %'],
            'rows' => $rows,
            'summary' => [
                'Total revenue' => round((float) $products->sum('net_revenue'), 2),
                'Total profit' => round((float) $products->sum('total_profit'), 2),
            ],
        ];
    }

    private function branchComparisonReport(
        SalesReportService $salesReportService,
        $user,
        string $from,
        string $to
    ): array {
        if ($user->role !== 'admin') {
            return ['error' => 'Branch comparison report sirf admin download kar sakta hai.'];
        }

        $branches = DB::table('branches')
            ->where('store_id', $user->store_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($branches->isEmpty()) {
            return ['error' => 'Is store mein koi branch nahi mili.'];
        }

        $totalSales = 0.0;
        $totalProfit = 0.0;

        $rows = $branches->map(function ($branch) use ($salesReportService, $user, $from, $to, &$totalSales, &$totalProfit) {
            $filters = $salesReportService->resolveFilters([
                'date_range' => 'custom',
                'date_from' => $from,
                'date_to' => $to,
                'bill_status' => 'all',
                'store_id' => $user->store_id,
                'branch_id' => (int) $branch->id,
            ]);
            $k = $salesReportService->getKpis($filters);

            $sales = (float) ($k['gross_sales'] ?? 0);
            $profit = (float) ($k['total_profit'] ?? 0);
            $totalSales += $sales;
            $totalProfit += $profit;

            return [
                $branch->name,
                (int) ($k['total_bills'] ?? 0),
                round($sales, 2),
                round($profit, 2),
                round((float) ($k['total_collected'] ?? 0), 2),
                round((float) ($k['total_due'] ?? 0), 2),
            ];
        })->all();

        return [
            'title' => 'Branch Comparison Report',
            'subtitle' => "{$from} to {$to} | All branches",
            'headings' => ['Branch', 'Bills', 'Gross Sales', 'Profit', 'Collected', 'Due'],
            'rows' => $rows,
            'summary' => [
                'Total sales (all branches)' => round($totalSales, 2),
                'Total profit (all branches)' => round($totalProfit, 2),
            ],
        ];
    }

    private function purchaseReport($user, ?int $branchId, string $from, string $to): array
    {
        $bills = DB::table('purchase_bills as pb')
            ->leftJoin('suppliers as s', 's.id', '=', 'pb.supplier_id')
            ->where('pb.store_id', $user->store_id)
            ->whereNull('pb.deleted_at')
            ->whereBetween('pb.bill_date', [$from, $to]);

        if ($branchId !== null) {
            $bills->where('pb.branch_id', $branchId);
        }

        $billRows = $bills
            ->orderBy('pb.bill_date')
            ->orderBy('pb.id')
            ->limit(5000)
            ->get(['pb.id', 'pb.bill_no', 'pb.bill_date', 'pb.total_amount', 's.name as supplier_name']);

        $paidByBill = $billRows->isEmpty()
            ? collect()
            : DB::table('purchase_payments')
                ->whereIn('purchase_bill_id', $billRows->pluck('id'))
                ->where('status', 'success')
                ->selectRaw('purchase_bill_id, SUM(amount) as paid')
                ->groupBy('purchase_bill_id')
                ->pluck('paid', 'purchase_bill_id');

        $total = 0.0;
        $paidTotal = 0.0;

        $rows = $billRows->map(function ($b) use ($paidByBill, &$total, &$paidTotal) {
            $amount = (float) $b->total_amount;
            $paid = (float) ($paidByBill[$b->id] ?? 0);
            $total += $amount;
            $paidTotal += $paid;

            return [
                $b->bill_no,
                $b->bill_date,
                $b->supplier_name ?? 'N/A',
                round($amount, 2),
                round($paid, 2),
                round(max(0, $amount - $paid), 2),
            ];
        })->all();

        return [
            'title' => 'Purchase Report',
            'subtitle' => "{$from} to {$to} | " . $this->branchLabel($user, $branchId),
            'headings' => ['Bill No', 'Date', 'Supplier', 'Total Amount', 'Paid', 'Due'],
            'rows' => $rows,
            'summary' => [
                'Total bills' => count($rows),
                'Total purchase' => round($total, 2),
                'Total paid' => round($paidTotal, 2),
                'Total due' => round(max(0, $total - $paidTotal), 2),
            ],
        ];
    }

    /* ───────────── Helpers ───────────── */

    private function branchLabel($user, ?int $branchId): string
    {
        if ($branchId === null) {
            return 'All branches';
        }

        $name = DB::table('branches')
            ->where('id', $branchId)
            ->where('store_id', $user->store_id)
            ->value('name');

        return 'Branch: ' . ($name ?: $branchId);
    }

    private function resolveAuthorizedBranch($user, ?int $requestedBranchId): ?int
    {
        if ($user->role === 'manager') {
            $branchId = !empty($user->branch_id)
                ? (int) $user->branch_id
                : DB::table('branch_staff')->where('user_id', $user->id)->value('branch_id');

            if (!$branchId) {
                abort(403, 'Manager ki assigned branch resolve nahi hui.');
            }

            $this->assertBranchBelongsToStore($user, (int) $branchId);

            return (int) $branchId;
        }

        // Admin: null or 0 means all branches in their own store.
        if (!$requestedBranchId) {
            return null;
        }

        $this->assertBranchBelongsToStore($user, $requestedBranchId);

        return $requestedBranchId;
    }

    private function assertBranchBelongsToStore($user, int $branchId): void
    {
        $exists = DB::table('branches')
            ->where('id', $branchId)
            ->where('store_id', $user->store_id)
            ->exists();

        if (!$exists) {
            abort(403, 'Invalid branch access.');
        }
    }
}