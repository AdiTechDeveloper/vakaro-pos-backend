<?php

namespace App\Http\Controllers\Concerns;

use App\Services\SalesReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extra AI business tools.
 * Put this file at: app/Http/Controllers/Concerns/AIExtraTools.php
 * Then add `use \App\Http\Controllers\Concerns\AIExtraTools;` inside AIController.
 *
 * Tools that depend on tables/columns I could not see (returns, expenses, cashier, GST)
 * detect the schema at runtime and answer honestly ("not available") instead of crashing.
 */
trait AIExtraTools
{
    private const DATE_ENUM = ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'custom'];

    /* ------------------------------------------------------------------ */
    /*  Tool definitions                                                   */
    /* ------------------------------------------------------------------ */

    protected function extraToolDefinitions(): array
    {
        $date = [
            'date_range' => ['type' => 'string', 'enum' => self::DATE_ENUM, 'description' => 'Use custom for a specific month/year/date range and fill date_from and date_to.'],
            'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD, only for custom.'],
            'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD, only for custom.'],
        ];

        $tool = fn(string $name, string $desc, array $props = [], array $required = []) => [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $desc,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $props ?: new \stdClass(),
                    'required' => $required,
                ],
            ],
        ];

        return [
            $tool('get_gst_summary', 'GST summary for a period: output GST (sales), input GST (purchases) and net GST payable.', $date),
            $tool('get_expense_summary', 'Expense totals for a period, category-wise, plus gross profit and NET profit (gross profit minus expenses).', $date),
            $tool('get_sales_returns', 'Sales returns / refunds for a period: number of returns and total returned amount.', $date),
            $tool('get_dead_stock', 'Dead / slow-moving stock: products that have stock but had NO sales in the last N days (default 30; use 30, 60 or 90).', [
                'days' => ['type' => 'integer', 'description' => 'No-sale window in days, 7 to 365. Default 30.'],
            ]),
            $tool('get_category_wise_sales', 'Category-wise quantity sold, revenue and profit for a period.', $date),
            $tool('get_cashier_wise_sales', 'Cashier / staff-wise bills and sales for a period.', $date),
            $tool('get_product_info', 'Price and stock of ONE named product (or a few matching names): selling price, available quantity, branch-wise. Use for questions like "Maggi ka price/stock kya hai".', [
                'product_name' => ['type' => 'string', 'description' => 'Product name or SKU mentioned by the user.'],
            ], ['product_name']),
            $tool('get_customer_dues', 'Customers who owe money (udhaar / pending due on bills): total due and the list of customers with the highest due.'),
        ];
    }

    protected function executeExtraTool(
        string $name,
        array $args,
        SalesReportService $svc,
        $user,
        ?int $branchId
    ): ?array {
        $known = ['get_gst_summary', 'get_expense_summary', 'get_sales_returns', 'get_dead_stock',
            'get_category_wise_sales', 'get_cashier_wise_sales', 'get_product_info', 'get_customer_dues'];

        if (!in_array($name, $known, true)) {
            return null;
        }

        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }

        return match ($name) {
            'get_gst_summary' => $this->getGstSummary($svc, $user, $branchId, $args),
            'get_expense_summary' => $this->getExpenseSummary($svc, $user, $branchId, $args),
            'get_sales_returns' => $this->getSalesReturns($user, $branchId, $args),
            'get_dead_stock' => $this->getDeadStock($svc, $user, $branchId, $args),
            'get_category_wise_sales' => $this->getCategoryWiseSales($svc, $user, $branchId, $args),
            'get_cashier_wise_sales' => $this->getCashierWiseSales($user, $branchId, $args),
            'get_product_info' => $this->getProductInfo($user, $branchId, $args),
            'get_customer_dues' => $this->getCustomerDues($user, $branchId),
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Shared helpers                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * One date resolver for every tool: today, yesterday, last_7_days, this_month, last_month, custom.
     * Returns from/to (Carbon), label, and 'service_input' to pass into SalesReportService::resolveFilters().
     */
    protected function resolveRange(array $a, string $default = 'this_month'): array
    {
        $r = $a['date_range'] ?? $default;
        $today = Carbon::today();

        switch ($r) {
            case 'today':
                $from = $today->copy();
                $to = $today->copy();
                break;
            case 'yesterday':
                $from = $today->copy()->subDay();
                $to = $from->copy();
                break;
            case 'last_7_days':
                $from = $today->copy()->subDays(6);
                $to = $today->copy();
                break;
            case 'last_month':
                $from = $today->copy()->subMonthNoOverflow()->startOfMonth();
                $to = $from->copy()->endOfMonth();
                break;
            case 'custom':
                try {
                    $from = Carbon::createFromFormat('Y-m-d', (string) ($a['date_from'] ?? ''))->startOfDay();
                    $to = Carbon::createFromFormat('Y-m-d', (string) ($a['date_to'] ?? ''))->startOfDay();
                } catch (\Throwable $e) {
                    return ['error' => 'A valid date range was not provided. Ask the user for the month or dates.'];
                }
                if ($from->gt($to)) {
                    return ['error' => 'Start date cannot be after the end date.'];
                }
                break;
            default:
                $r = 'this_month';
                $from = $today->copy()->startOfMonth();
                $to = $today->copy();
        }

        $input = in_array($r, ['today', 'yesterday', 'last_7_days', 'this_month'], true)
            ? ['date_range' => $r]
            : ['date_range' => 'custom', 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString()];

        return [
            'from' => $from->copy()->startOfDay(),
            'to' => $to->copy()->endOfDay(),
            'label' => $r,
            'period' => $from->toDateString() . ' to ' . $to->toDateString(),
            'service_input' => $input,
        ];
    }

    /**
     * Excludes cancelled / draft bills in raw sales_bills queries so totals match the other tools.
     * Run `SELECT DISTINCT bill_status FROM sales_bills` and adjust the list below to your real values.
     */
    protected function excludeInvalidBills($query, string $column = 'bill_status')
    {
        if (Schema::hasColumn('sales_bills', 'bill_status')) {
            $query->whereNotIn(DB::raw("LOWER({$column})"), ['cancelled', 'canceled', 'void', 'draft', 'deleted']);
        }
        return $query;
    }

    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $col) {
            if (Schema::hasColumn($table, $col)) {
                return $col;
            }
        }
        return null;
    }

    private function serviceFilters(SalesReportService $svc, $user, ?int $branchId, array $range): array
    {
        return $svc->resolveFilters(array_merge($range['service_input'], [
            'bill_status' => 'all',
            'store_id' => $user->store_id,
            'branch_id' => $branchId,
        ]));
    }

    /* ------------------------------------------------------------------ */
    /*  1. GST summary                                                     */
    /* ------------------------------------------------------------------ */

    private function getGstSummary(SalesReportService $svc, $user, ?int $branchId, array $a): array
    {
        $range = $this->resolveRange($a, 'this_month');
        if (isset($range['error'])) {
            return $range;
        }

        // Output GST (sales)
        $gstCol = $this->firstExistingColumn('sales_bills', ['gst_amount', 'total_gst', 'tax_amount']);
        if ($gstCol) {
            $q = DB::table('sales_bills')
                ->where('store_id', $user->store_id)
                ->whereBetween('created_at', [$range['from'], $range['to']]);
            if ($branchId !== null) {
                $q->where('branch_id', $branchId);
            }
            $this->excludeInvalidBills($q);
            $outputGst = (float) $q->sum($gstCol);
            $outputSource = "sales_bills.{$gstCol}";
        } else {
            $kpis = $svc->getKpis($this->serviceFilters($svc, $user, $branchId, $range));
            $outputGst = (float) ($kpis['total_gst_bills'] ?? 0);
            $outputSource = 'SalesReportService KPI total_gst_bills';
        }

        // Input GST (purchases)
        $inputGst = null;
        $inputNote = null;
        $lines = DB::table('purchase_lines as pl')
            ->join('purchase_bills as pb', 'pb.id', '=', 'pl.purchase_bill_id')
            ->where('pb.store_id', $user->store_id)
            ->whereNull('pb.deleted_at')
            ->whereBetween('pb.bill_date', [$range['from']->toDateString(), $range['to']->toDateString()]);
        if ($branchId !== null) {
            $lines->where('pb.branch_id', $branchId);
        }

        if (Schema::hasColumn('purchase_lines', 'gst_amount')) {
            $inputGst = (float) $lines->sum('pl.gst_amount');
        } elseif ($rateCol = $this->firstExistingColumn('purchase_lines', ['gst_rate_percent', 'gst_rate', 'gst_percent'])) {
            $inputGst = (float) $lines->sum(DB::raw("pl.qty * pl.purchase_rate * pl.{$rateCol} / 100"));
            $inputNote = 'Input GST is calculated as qty x purchase rate x GST % (assumes tax-exclusive rates).';
        } else {
            $inputNote = 'Purchase GST columns were not found, so input GST is not available.';
        }

        return [
            'period' => $range['period'],
            'output_gst_on_sales' => round($outputGst, 2),
            'input_gst_on_purchases' => $inputGst !== null ? round($inputGst, 2) : null,
            'net_gst_payable' => $inputGst !== null ? round($outputGst - $inputGst, 2) : null,
            'output_gst_source' => $outputSource,
            'note' => $inputNote ?? 'Net GST payable = output GST minus input GST. This is an estimate from recorded bills, not a filed GST return.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  2. Expense summary + net profit                                    */
    /* ------------------------------------------------------------------ */

    private function getExpenseSummary(SalesReportService $svc, $user, ?int $branchId, array $a): array
    {
        $range = $this->resolveRange($a, 'this_month');
        if (isset($range['error'])) {
            return $range;
        }

        if (!Schema::hasTable('expenses')) {
            return ['message' => 'The expenses table was not found, so expense data is not available yet.'];
        }

        $dateCol = $this->firstExistingColumn('expenses', ['expense_date', 'date', 'created_at']);
        if (!$dateCol || !Schema::hasColumn('expenses', 'amount')) {
            return ['message' => 'Expense table does not have the expected amount/date columns.'];
        }

        $q = DB::table('expenses as e');

        if (Schema::hasColumn('expenses', 'store_id')) {
            $q->where('e.store_id', $user->store_id);
        } elseif (Schema::hasColumn('expenses', 'branch_id')) {
            $q->join('branches as eb', 'eb.id', '=', 'e.branch_id')->where('eb.store_id', $user->store_id);
        } else {
            return ['message' => 'Expenses cannot be scoped to this store (no store_id/branch_id column).'];
        }

        if ($branchId !== null && Schema::hasColumn('expenses', 'branch_id')) {
            $q->where('e.branch_id', $branchId);
        }
        if (Schema::hasColumn('expenses', 'deleted_at')) {
            $q->whereNull('e.deleted_at');
        }

        $q->whereBetween("e.{$dateCol}", [
            $dateCol === 'created_at' ? $range['from'] : $range['from']->toDateString(),
            $dateCol === 'created_at' ? $range['to'] : $range['to']->toDateString(),
        ]);

        $categoryExpr = "'Uncategorized'";
        if (Schema::hasColumn('expenses', 'category_id') && Schema::hasTable('expense_categories')) {
            $q->leftJoin('expense_categories as ec', 'ec.id', '=', 'e.category_id');
            $categoryExpr = "COALESCE(ec.name, 'Uncategorized')";
        } elseif (Schema::hasColumn('expenses', 'category')) {
            $categoryExpr = "COALESCE(e.category, 'Uncategorized')";
        }

        $rows = $q->selectRaw("{$categoryExpr} as category_name, COUNT(*) as entries, SUM(e.amount) as total")
            ->groupBy(DB::raw($categoryExpr))
            ->orderByDesc('total')
            ->get();

        $totalExpense = round((float) $rows->sum('total'), 2);

        $kpis = $svc->getKpis($this->serviceFilters($svc, $user, $branchId, $range));
        $grossProfit = round((float) ($kpis['total_profit'] ?? 0), 2);

        return [
            'period' => $range['period'],
            'total_expenses' => $totalExpense,
            'gross_profit' => $grossProfit,
            'net_profit' => round($grossProfit - $totalExpense, 2),
            'categories' => $rows->map(fn($r) => [
                'category_name' => $r->category_name,
                'entries' => (int) $r->entries,
                'total' => round((float) $r->total, 2),
            ])->all(),
            'note' => 'Net profit = gross profit (sales minus cost of goods) minus recorded expenses for the same period.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  3. Sales returns                                                   */
    /* ------------------------------------------------------------------ */

    private function getSalesReturns($user, ?int $branchId, array $a): array
    {
        $range = $this->resolveRange($a, 'this_month');
        if (isset($range['error'])) {
            return $range;
        }

        $table = collect(['sales_returns', 'sale_returns'])->first(fn($t) => Schema::hasTable($t));
        if (!$table) {
            return ['message' => 'A sales returns table was not found, so return data is not available yet.'];
        }

        $amountCol = $this->firstExistingColumn($table, ['total_amount', 'refund_amount', 'return_amount', 'amount']);
        if (!$amountCol) {
            return ['message' => 'The returns table has no recognizable amount column.'];
        }

        $q = DB::table("{$table} as r");
        if (Schema::hasColumn($table, 'store_id')) {
            $q->where('r.store_id', $user->store_id);
        } elseif (Schema::hasColumn($table, 'branch_id')) {
            $q->join('branches as rb', 'rb.id', '=', 'r.branch_id')->where('rb.store_id', $user->store_id);
        } else {
            return ['message' => 'Returns cannot be scoped to this store.'];
        }

        if ($branchId !== null && Schema::hasColumn($table, 'branch_id')) {
            $q->where('r.branch_id', $branchId);
        }
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('r.deleted_at');
        }

        $row = $q->whereBetween('r.created_at', [$range['from'], $range['to']])
            ->selectRaw("COUNT(*) as returns_count, COALESCE(SUM(r.{$amountCol}), 0) as returns_total")
            ->first();

        return [
            'period' => $range['period'],
            'returns_count' => (int) $row->returns_count,
            'returns_total' => round((float) $row->returns_total, 2),
            'note' => 'Totals are from the recorded sales returns. Gross sales numbers from other tools may or may not already net these out.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  4. Dead stock                                                      */
    /* ------------------------------------------------------------------ */

    private function getDeadStock(SalesReportService $svc, $user, ?int $branchId, array $a): array
    {
        $days = max(7, min((int) ($a['days'] ?? 30), 365));
        $from = Carbon::today()->subDays($days - 1);

        $filters = $svc->resolveFilters([
            'date_range' => 'custom',
            'date_from' => $from->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'bill_status' => 'all',
            'store_id' => $user->store_id,
            'branch_id' => $branchId,
        ]);
        $soldIds = collect($svc->getProductPerformance($filters)['rows'])
            ->pluck('product_id')
            ->unique()
            ->values()
            ->all();

        $q = DB::table('inventories as i')
            ->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('b.store_id', $user->store_id)
            ->whereNull('i.deleted_at');

        if ($branchId !== null) {
            $q->where('i.branch_id', $branchId);
        }
        if (!empty($soldIds)) {
            $q->whereNotIn('p.id', $soldIds);
        }

        $q->selectRaw('
                p.name as product_name,
                SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) as available_qty,
                SUM((i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) * COALESCE(i.selling_price, 0)) as stock_value
            ')
            ->groupBy('p.id', 'p.name')
            ->havingRaw('SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) > 0');

        // Do not flag stock that was only received inside the window.
        if (Schema::hasColumn('inventories', 'created_at')) {
            $q->havingRaw('MIN(i.created_at) <= ?', [$from->copy()->startOfDay()]);
        }

        $rows = $q->orderByDesc('stock_value')->get();

        return [
            'no_sale_days' => $days,
            'dead_stock_product_count' => $rows->count(),
            'total_stock_value_at_selling_price' => round((float) $rows->sum('stock_value'), 2),
            'products' => $rows->take(30)->map(fn($r) => [
                'product_name' => $r->product_name,
                'available_qty' => (float) $r->available_qty,
                'stock_value' => round((float) $r->stock_value, 2),
            ])->values()->all(),
            'truncated' => $rows->count() > 30,
            'note' => "Products with available stock and no sale in the last {$days} days, sorted by stock value (at selling price). At most 30 are listed; tell the user if truncated is true.",
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  5. Category-wise sales                                             */
    /* ------------------------------------------------------------------ */

    private function getCategoryWiseSales(SalesReportService $svc, $user, ?int $branchId, array $a): array
    {
        $range = $this->resolveRange($a, 'this_month');
        if (isset($range['error'])) {
            return $range;
        }

        $perf = collect($svc->getProductPerformance($this->serviceFilters($svc, $user, $branchId, $range))['rows']);
        if ($perf->isEmpty()) {
            return ['period' => $range['period'], 'categories' => [], 'message' => 'No sales found in this period.'];
        }

        $productCategory = DB::table('products')
            ->where('store_id', $user->store_id)
            ->whereIn('id', $perf->pluck('product_id'))
            ->pluck('category_id', 'id');

        $categoryNames = DB::table('categories')
            ->where('store_id', $user->store_id)
            ->pluck('name', 'id');

        $grouped = $perf->groupBy(function ($row) use ($productCategory, $categoryNames) {
            $cid = $productCategory[$row->product_id] ?? null;
            return $cid && isset($categoryNames[$cid]) ? $categoryNames[$cid] : 'Uncategorized';
        })->map(fn($rows, $name) => [
            'category_name' => $name,
            'qty_sold' => round((float) $rows->sum('qty_sold'), 2),
            'revenue' => round((float) $rows->sum('net_revenue'), 2),
            'profit' => round((float) $rows->sum('total_profit'), 2),
        ])->sortByDesc('revenue')->values();

        return [
            'period' => $range['period'],
            'categories' => $grouped->all(),
            'totals' => [
                'qty_sold' => round($grouped->sum('qty_sold'), 2),
                'revenue' => round($grouped->sum('revenue'), 2),
                'profit' => round($grouped->sum('profit'), 2),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  6. Cashier-wise sales                                              */
    /* ------------------------------------------------------------------ */

    private function getCashierWiseSales($user, ?int $branchId, array $a): array
    {
        $range = $this->resolveRange($a, 'today');
        if (isset($range['error'])) {
            return $range;
        }

        // Column name comes from this fixed whitelist only.
        $col = $this->firstExistingColumn('sales_bills', ['cashier_id', 'created_by', 'user_id']);
        if (!$col) {
            return ['message' => 'sales_bills has no cashier/user column, so cashier-wise sales are not available.'];
        }

        $q = DB::table('sales_bills as sb')
            ->leftJoin('users as u', 'u.id', '=', "sb.{$col}")
            ->where('sb.store_id', $user->store_id)
            ->whereBetween('sb.created_at', [$range['from'], $range['to']]);

        if ($branchId !== null) {
            $q->where('sb.branch_id', $branchId);
        }
        $this->excludeInvalidBills($q, 'sb.bill_status');

        $rows = $q->selectRaw("COALESCE(u.name, 'Unknown') as cashier_name, COUNT(sb.id) as bills, COALESCE(SUM(sb.total_amount),0) as sales, COALESCE(SUM(sb.paid_amount),0) as collected")
            ->groupBy("sb.{$col}", 'u.name')
            ->orderByDesc('sales')
            ->get();

        return [
            'period' => $range['period'],
            'cashiers' => $rows->map(fn($r) => [
                'cashier_name' => $r->cashier_name,
                'total_bills' => (int) $r->bills,
                'sales' => round((float) $r->sales, 2),
                'collected_amount' => round((float) $r->collected, 2),
            ])->all(),
            'totals' => [
                'total_bills' => (int) $rows->sum('bills'),
                'sales' => round((float) $rows->sum('sales'), 2),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  7. Single product info                                             */
    /* ------------------------------------------------------------------ */

    private function getProductInfo($user, ?int $branchId, array $a): array
    {
        $search = trim((string) ($a['product_name'] ?? ''));
        if ($search === '') {
            return ['error' => 'Product name is required.'];
        }
        $like = '%' . addcslashes($search, '%_\\') . '%';

        $base = DB::table('inventories as i')
            ->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('b.store_id', $user->store_id)
            ->whereNull('i.deleted_at')
            ->where(function ($q) use ($like) {
                $q->where('p.name', 'like', $like)->orWhere('p.sku', 'like', $like);
            });

        if ($branchId !== null) {
            $base->where('i.branch_id', $branchId);
        }

        $products = (clone $base)
            ->selectRaw('
                p.id as product_id,
                p.name as product_name,
                COALESCE(MAX(i.selling_price), 0) as selling_price,
                SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) as available_qty
            ')
            ->groupBy('p.id', 'p.name')
            ->orderBy('p.name')
            ->limit(10)
            ->get();

        if ($products->isEmpty()) {
            return ['product_name_searched' => $search, 'products' => [], 'message' => 'No matching product with stock records was found.'];
        }

        $branchWise = (clone $base)
            ->whereIn('p.id', $products->pluck('product_id'))
            ->selectRaw('p.id as product_id, b.name as branch_name, SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) as available_qty')
            ->groupBy('p.id', 'b.id', 'b.name')
            ->get()
            ->groupBy('product_id');

        return [
            'product_name_searched' => $search,
            'products' => $products->map(fn($p) => [
                'product_name' => $p->product_name,
                'selling_price' => round((float) $p->selling_price, 2),
                'available_qty' => (float) $p->available_qty,
                'branch_wise_stock' => ($branchWise[$p->product_id] ?? collect())->map(fn($r) => [
                    'branch_name' => $r->branch_name,
                    'available_qty' => (float) $r->available_qty,
                ])->values()->all(),
            ])->all(),
            'note' => 'At most 10 matching products are returned. Selling price is the highest recorded batch price.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  8. Customer dues (udhaar)                                          */
    /* ------------------------------------------------------------------ */

    private function getCustomerDues($user, ?int $branchId): array
    {
        $scope = function () use ($user, $branchId) {
            $q = DB::table('sales_bills')
                ->where('store_id', $user->store_id)
                ->where('due_amount', '>', 0);
            if ($branchId !== null) {
                $q->where('branch_id', $branchId);
            }
            return $this->excludeInvalidBills($q);
        };

        $perCustomer = $scope()
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as pending_bills, SUM(due_amount) as due')
            ->groupBy('customer_id')
            ->orderByDesc('due')
            ->get();

        $walkInDue = (float) $scope()->whereNull('customer_id')->sum('due_amount');

        $customers = DB::table('customers')
            ->whereIn('id', $perCustomer->take(30)->pluck('customer_id'))
            ->get(['id', 'name', 'mobile'])
            ->keyBy('id');

        return [
            'customers_with_due' => $perCustomer->count(),
            'total_customer_due' => round((float) $perCustomer->sum('due'), 2),
            'walk_in_bills_due' => round($walkInDue, 2),
            'customers' => $perCustomer->take(30)->map(fn($c) => [
                'customer_name' => $customers[$c->customer_id]->name ?? 'Unknown',
                'mobile' => $customers[$c->customer_id]->mobile ?? null,
                'pending_bills' => (int) $c->pending_bills,
                'due_amount' => round((float) $c->due, 2),
            ])->values()->all(),
            'truncated' => $perCustomer->count() > 30,
            'note' => 'Due is the sum of due_amount on valid sales bills. Customer opening balances are not included. At most 30 customers are listed; tell the user if truncated is true.',
        ];
    }
}