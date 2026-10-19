<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /api/dashboard/insights?range=today|week|month&branch_id=
 *
 * Table / column naam alag hon to bhi chalega: har jagah candidates me se jo
 * pehla column milta hai wo use hota hai. Koi section fail ho to wo null
 * jaata hai (log me error aata hai) aur baaki dashboard chalta rehta hai.
 */
class DashboardInsightsController extends Controller
{
    private const LOW_STOCK_LIMIT = 5;

    public function show(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user && in_array($user->role, ['admin', 'manager'], true),
            403,
            'Not authorized.'
        );

        $storeId = (int) $user->store_id;
        $branchId = $this->resolveBranch($user, (int) $request->input('branch_id', 0));
        $range = in_array($request->input('range'), ['today', 'week', 'month'], true)
            ? $request->input('range')
            : 'today';

        [$from, $to, $prevFrom, $prevTo] = $this->period($range);

        $sales = $this->safe(fn () => $this->sales($storeId, $branchId, $from, $to, $prevFrom, $prevTo));
        $expenses = $this->safe(fn () => $this->expenses($storeId, $branchId, $from, $to));
        $profit = $this->safe(fn () => $this->profit($storeId, $branchId, $from, $to, $sales, $expenses));

        return response()->json([
            'status' => true,
            'data' => [
                'range' => $range,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'sales' => $sales,
                'profit' => $profit,
                'expenses' => $expenses,
                'payments' => $this->safe(fn () => $this->payments($storeId, $branchId, $from, $to)),
                'trend' => $this->safe(fn () => $this->trend($storeId, $branchId)),
                'peak' => $this->safe(fn () => $this->peak($storeId, $branchId)),
                'top_profit' => $this->safe(fn () => $this->topProfit($storeId, $branchId)),
                'stock_risk' => $this->safe(fn () => $this->stockRisk($storeId, $branchId)),
                'dead_stock' => $this->safe(fn () => $this->deadStock($storeId, $branchId)),
                'expiry' => $this->safe(fn () => $this->expiry($storeId, $branchId)),
                'customers' => $this->safe(fn () => $this->topCustomers($storeId, $branchId)),
                'customers_due_count' => $this->safe(fn () => $this->dueCustomerCount($storeId, $branchId)),
                'branches' => $user->role === 'admin' ? $this->safe(fn () => $this->branchesSales($storeId, $from, $to)) : null,
            ],
        ]);
    }

    /* ------------------------------------------------------------ */
    /*  Helpers                                                      */
    /* ------------------------------------------------------------ */

    private function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    private function col(string $table, array $candidates): ?string
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        foreach ($candidates as $c) {
            if (Schema::hasColumn($table, $c)) {
                return $c;
            }
        }
        return null;
    }

    private function firstTable(array $candidates): ?string
    {
        foreach ($candidates as $t) {
            if (Schema::hasTable($t)) {
                return $t;
            }
        }
        return null;
    }

    private function period(string $range): array
    {
        $today = Carbon::today();

        $from = match ($range) {
            'week' => $today->copy()->startOfWeek(),
            'month' => $today->copy()->startOfMonth(),
            default => $today->copy(),
        };

        $days = $from->diffInDays($today) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        return [$from, $today, $prevFrom, $prevTo];
    }

    private function resolveBranch($user, int $requested): ?int
    {
        if ($user->role === 'manager') {
            $id = ! empty($user->branch_id)
                ? (int) $user->branch_id
                : DB::table('branch_staff')->where('user_id', $user->id)->value('branch_id');
            abort_if(! $id, 403, 'Manager ki assigned branch resolve nahi hui.');
            return (int) $id;
        }

        if ($requested > 0) {
            $ok = DB::table('branches')
                ->where('id', $requested)
                ->where('store_id', $user->store_id)
                ->exists();
            abort_if(! $ok, 403, 'Invalid branch access.');
            return $requested;
        }

        return null;
    }

    private function billTotalCol(): string
    {
        $c = $this->col('sales_bills', ['total_amount', 'grand_total', 'net_amount', 'total']);
        if (! $c) {
            throw new \RuntimeException('sales_bills total column not found');
        }
        return $c;
    }

    private function bills(int $storeId, ?int $branchId, Carbon $from, Carbon $to)
    {
        $q = DB::table('sales_bills as sb')
            ->where('sb.store_id', $storeId)
            ->whereDate('sb.created_at', '>=', $from->toDateString())
            ->whereDate('sb.created_at', '<=', $to->toDateString());

        if ($branchId) {
            $q->where('sb.branch_id', $branchId);
        }
        if (Schema::hasColumn('sales_bills', 'deleted_at')) {
            $q->whereNull('sb.deleted_at');
        }

        return $q;
    }

    /** sales_bill_items ka structure (jo mile). */
    private function itemsMeta(): ?array
    {
        $table = $this->firstTable(['sales_bill_items', 'sale_bill_items', 'sales_items', 'sale_items']);
        if (! $table) {
            return null;
        }

        $fk = $this->col($table, ['sales_bill_id', 'sale_bill_id', 'bill_id']);
        $qty = $this->col($table, ['quantity', 'qty']);
        $product = $this->col($table, ['product_id']);

        if (! $fk || ! $qty || ! $product) {
            return null;
        }

        return [
            'table' => $table,
            'fk' => $fk,
            'qty' => $qty,
            'product' => $product,
            'line' => $this->col($table, ['line_total', 'total_amount', 'total', 'amount', 'subtotal']),
            'price' => $this->col($table, ['unit_price', 'price', 'selling_price']),
            'cost' => $this->col($table, ['cost_price', 'purchase_price']),
        ];
    }

    private function itemsQuery(array $m, int $storeId, ?int $branchId, Carbon $from, Carbon $to)
    {
        $q = DB::table("{$m['table']} as i")
            ->join('sales_bills as sb', 'sb.id', '=', "i.{$m['fk']}")
            ->where('sb.store_id', $storeId)
            ->whereDate('sb.created_at', '>=', $from->toDateString())
            ->whereDate('sb.created_at', '<=', $to->toDateString());

        if ($branchId) {
            $q->where('sb.branch_id', $branchId);
        }
        if (Schema::hasColumn('sales_bills', 'deleted_at')) {
            $q->whereNull('sb.deleted_at');
        }

        return $q;
    }

    private function stockMeta(): ?array
    {
        $table = $this->firstTable([
            'product_batches', 'stock_batches', 'batches', 'inventory_batches', 'stocks', 'inventories',
        ]);
        if (! $table) {
            return null;
        }

        $qty = $this->col($table, ['quantity', 'stock', 'qty', 'available_qty', 'current_stock']);
        $product = $this->col($table, ['product_id']);
        if (! $qty || ! $product) {
            return null;
        }

        return [
            'table' => $table,
            'qty' => $qty,
            'product' => $product,
            'branch' => $this->col($table, ['branch_id']),
            'expiry' => $this->col($table, ['expiry_date', 'expiry', 'exp_date']),
        ];
    }

    /** [product_id => total qty] */
    private function stockByProduct(array $m, ?int $branchId)
    {
        $q = DB::table($m['table'])
            ->selectRaw("{$m['product']} as pid, SUM({$m['qty']}) as qty")
            ->groupBy($m['product']);

        if ($branchId && $m['branch']) {
            $q->where($m['branch'], $branchId);
        }

        return $q->pluck('qty', 'pid');
    }

    /* ------------------------------------------------------------ */
    /*  Sections                                                     */
    /* ------------------------------------------------------------ */

    private function sales($storeId, $branchId, $from, $to, $prevFrom, $prevTo): array
    {
        $t = $this->billTotalCol();

        $cur = $this->bills($storeId, $branchId, $from, $to)
            ->selectRaw("COALESCE(SUM(sb.$t),0) as total, COUNT(*) as bills")
            ->first();

        $prev = $this->bills($storeId, $branchId, $prevFrom, $prevTo)
            ->selectRaw("COALESCE(SUM(sb.$t),0) as total")
            ->first();

        $total = (float) $cur->total;
        $bills = (int) $cur->bills;

        return [
            'total' => round($total, 2),
            'prev_total' => round((float) $prev->total, 2),
            'bills' => $bills,
            'avg_bill' => $bills ? round($total / $bills, 2) : 0,
        ];
    }

    private function expenses($storeId, $branchId, $from, $to): array
    {
        $q = DB::table('expenses')
            ->where('store_id', $storeId)
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString());

        if (Schema::hasColumn('expenses', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }

        $row = $q->selectRaw(
            "COALESCE(SUM(amount),0) as total,
             COALESCE(SUM(CASE WHEN payment_mode = 'cash' THEN amount ELSE 0 END),0) as cash"
        )->first();

        $total = (float) $row->total;
        $cash = (float) $row->cash;

        return [
            'total' => round($total, 2),
            'cash' => round($cash, 2),
            'bank' => round($total - $cash, 2),
        ];
    }

    /** profit = sales - tax - COGS  (ProfitLossWidget wala hi formula) */
    private function profit($storeId, $branchId, $from, $to, ?array $sales, ?array $expenses): ?array
    {
        $m = $this->itemsMeta();
        if (! $m || ! $sales) {
            return null;
        }

        $taxCol = $this->col('sales_bills', ['tax_amount', 'gst_amount', 'total_tax', 'tax_total']);
        $tax = $taxCol
            ? (float) $this->bills($storeId, $branchId, $from, $to)->sum("sb.$taxCol")
            : 0.0;

        $q = $this->itemsQuery($m, $storeId, $branchId, $from, $to);

        if ($m['cost']) {
            $cogs = (float) $q->selectRaw("COALESCE(SUM(i.{$m['qty']} * i.{$m['cost']}),0) as c")->value('c');
        } else {
            $cogs = (float) $q->join('products as p', 'p.id', '=', "i.{$m['product']}")
                ->selectRaw("COALESCE(SUM(i.{$m['qty']} * p.cost_price),0) as c")
                ->value('c');
        }

        $net = $sales['total'] - $tax;
        $profit = $net - $cogs;

        return [
            'amount' => round($profit, 2),
            'cogs' => round($cogs, 2),
            'margin_pct' => $net > 0 ? round($profit / $net * 100, 1) : 0,
            'after_expenses' => round($profit - ($expenses['total'] ?? 0), 2),
        ];
    }

    private function payments($storeId, $branchId, $from, $to): array
    {
        $q = DB::table('sales_bill_payments as sbp')
            ->join('sales_bills as sb', 'sb.id', '=', 'sbp.sales_bill_id')
            ->where('sb.store_id', $storeId)
            ->where('sbp.status', 'success')
            ->whereDate('sb.created_at', '>=', $from->toDateString())
            ->whereDate('sb.created_at', '<=', $to->toDateString());

        if ($branchId) {
            $q->where('sb.branch_id', $branchId);
        }

        $rows = $q->groupBy('sbp.method')
            ->selectRaw('LOWER(sbp.method) as method, SUM(sbp.amount) as total')
            ->pluck('total', 'method');

        $cash = (float) ($rows['cash'] ?? 0);
        $online = (float) collect($rows)->except('cash', 'wallet')->sum();

        return ['cash' => round($cash, 2), 'online' => round($online, 2)];
    }

    /** Last 30 din ki daily sales */
    private function trend($storeId, $branchId): array
    {
        $t = $this->billTotalCol();
        $to = Carbon::today();
        $from = $to->copy()->subDays(29);

        $rows = $this->bills($storeId, $branchId, $from, $to)
            ->selectRaw("DATE(sb.created_at) as d, SUM(sb.$t) as total")
            ->groupBy(DB::raw('DATE(sb.created_at)'))
            ->pluck('total', 'd');

        $out = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $out[] = ['d' => $key, 'total' => round((float) ($rows[$key] ?? 0), 2)];
        }

        return $out;
    }

    /** Last 30 din: kaunse ghante / din zyada bills */
    private function peak($storeId, $branchId): array
    {
        $to = Carbon::today();
        $from = $to->copy()->subDays(29);

        $hours = $this->bills($storeId, $branchId, $from, $to)
            ->selectRaw('HOUR(sb.created_at) as h, COUNT(*) as bills')
            ->groupBy(DB::raw('HOUR(sb.created_at)'))
            ->orderByDesc('bills')
            ->limit(3)
            ->get()
            ->map(fn ($r) => ['hour' => (int) $r->h, 'bills' => (int) $r->bills])
            ->all();

        $day = $this->bills($storeId, $branchId, $from, $to)
            ->selectRaw('DAYOFWEEK(sb.created_at) as w, COUNT(*) as bills')
            ->groupBy(DB::raw('DAYOFWEEK(sb.created_at)'))
            ->orderByDesc('bills')
            ->first();

        $names = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];

        return [
            'hours' => $hours,
            'day' => $day ? ['name' => $names[(int) $day->w] ?? '', 'bills' => (int) $day->bills] : null,
        ];
    }

    /** Last 30 din: sabse zyada profit dene wale products (approx, tax alag nahi) */
    private function topProfit($storeId, $branchId): ?array
    {
        $m = $this->itemsMeta();
        if (! $m) {
            return null;
        }

        $revenue = $m['line']
            ? "SUM(i.{$m['line']})"
            : ($m['price'] ? "SUM(i.{$m['qty']} * i.{$m['price']})" : null);
        if (! $revenue) {
            return null;
        }

        $costExpr = $m['cost'] ? "i.{$m['cost']}" : 'p.cost_price';
        $to = Carbon::today();
        $from = $to->copy()->subDays(29);

        return $this->itemsQuery($m, $storeId, $branchId, $from, $to)
            ->join('products as p', 'p.id', '=', "i.{$m['product']}")
            ->groupBy('p.id', 'p.name')
            ->selectRaw("p.name, SUM(i.{$m['qty']}) as qty, ($revenue - SUM(i.{$m['qty']} * $costExpr)) as profit")
            ->orderByDesc('profit')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'qty' => (float) $r->qty,
                'profit' => round((float) $r->profit, 2),
            ])
            ->all();
    }

    private function stockRisk($storeId, $branchId): ?array
    {
        $sm = $this->stockMeta();
        if (! $sm) {
            return null;
        }

        $stock = $this->stockByProduct($sm, $branchId);
        $limit = self::LOW_STOCK_LIMIT;

        $out = $stock->filter(fn ($q) => (float) $q <= 0)->count();
        $low = $stock->filter(fn ($q) => (float) $q > 0 && (float) $q <= $limit)->count();

        $bestLow = [];
        $im = $this->itemsMeta();
        if ($im) {
            $to = Carbon::today();
            $from = $to->copy()->subDays(29);

            $best = $this->itemsQuery($im, $storeId, $branchId, $from, $to)
                ->join('products as p', 'p.id', '=', "i.{$im['product']}")
                ->groupBy('p.id', 'p.name')
                ->selectRaw("p.id, p.name, SUM(i.{$im['qty']}) as sold")
                ->orderByDesc('sold')
                ->limit(8)
                ->get();

            foreach ($best as $b) {
                $left = (float) ($stock[$b->id] ?? 0);
                if ($left <= $limit) {
                    $bestLow[] = [
                        'name' => $b->name,
                        'sold' => (float) $b->sold,
                        'left' => $left,
                    ];
                }
            }
        }

        return [
            'out' => $out,
            'low' => $low,
            'limit' => $limit,
            'best_sellers_low' => array_slice($bestLow, 0, 5),
        ];
    }

    /** Stock hai par 30 din se bika nahi */
    private function deadStock($storeId, $branchId): ?array
    {
        $sm = $this->stockMeta();
        $im = $this->itemsMeta();
        if (! $sm || ! $im) {
            return null;
        }

        $to = Carbon::today();
        $from = $to->copy()->subDays(29);

        $soldIds = $this->itemsQuery($im, $storeId, $branchId, $from, $to)
            ->distinct()
            ->pluck("i.{$im['product']}")
            ->all();

        $stock = $this->stockByProduct($sm, $branchId)
            ->filter(fn ($q, $pid) => (float) $q > 0 && ! in_array($pid, $soldIds, false));

        if ($stock->isEmpty()) {
            return ['count' => 0, 'value' => 0, 'items' => []];
        }

        $products = DB::table('products')
            ->whereIn('id', $stock->keys()->all())
            ->get(['id', 'name', 'cost_price'])
            ->keyBy('id');

        $rows = $stock->map(function ($qty, $pid) use ($products) {
            $p = $products[$pid] ?? null;
            return [
                'name' => $p->name ?? 'Unknown',
                'qty' => (float) $qty,
                'value' => round((float) $qty * (float) ($p->cost_price ?? 0), 2),
            ];
        })->sortByDesc('value')->values();

        return [
            'count' => $rows->count(),
            'value' => round($rows->sum('value'), 2),
            'items' => $rows->take(5)->all(),
        ];
    }

    private function expiry($storeId, $branchId): ?array
    {
        $sm = $this->stockMeta();
        if (! $sm || ! $sm['expiry']) {
            return null;
        }

        $base = DB::table($sm['table'])->where($sm['qty'], '>', 0)->whereNotNull($sm['expiry']);
        if ($branchId && $sm['branch']) {
            $base->where($sm['branch'], $branchId);
        }

        $today = Carbon::today()->toDateString();
        $soon = Carbon::today()->addDays(30)->toDateString();

        return [
            'expired' => (clone $base)->whereDate($sm['expiry'], '<', $today)->count(),
            'soon' => (clone $base)->whereDate($sm['expiry'], '>=', $today)->whereDate($sm['expiry'], '<=', $soon)->count(),
        ];
    }

    /** Last 30 din: sabse zyada kharidne wale customers */
    private function topCustomers($storeId, $branchId): ?array
    {
        $cust = $this->col('sales_bills', ['customer_id']);
        if (! $cust || ! Schema::hasTable('customers')) {
            return null;
        }

        $t = $this->billTotalCol();
        $to = Carbon::today();
        $from = $to->copy()->subDays(29);

        return $this->bills($storeId, $branchId, $from, $to)
            ->join('customers as c', 'c.id', '=', "sb.$cust")
            ->whereNotNull("sb.$cust")
            ->groupBy('c.id', 'c.name')
            ->selectRaw("c.name, COUNT(*) as bills, SUM(sb.$t) as total")
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'bills' => (int) $r->bills,
                'total' => round((float) $r->total, 2),
            ])
            ->all();
    }

    private function dueCustomerCount($storeId, $branchId): ?int
    {
        $cust = $this->col('sales_bills', ['customer_id']);
        if (! $cust) {
            return null;
        }

        $q = DB::table('sales_bills')
            ->where('store_id', $storeId)
            ->where('due_amount', '>', 0)
            ->whereNotNull($cust);

        if ($branchId) {
            $q->where('branch_id', $branchId);
        }

        return (int) $q->distinct()->count($cust);
    }

    private function branchesSales($storeId, $from, $to): ?array
    {
        $t = $this->billTotalCol();

        $rows = $this->bills($storeId, null, $from, $to)
            ->join('branches as b', 'b.id', '=', 'sb.branch_id')
            ->groupBy('b.id', 'b.name')
            ->selectRaw("b.id, b.name, COUNT(*) as bills, SUM(sb.$t) as total")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'bills' => (int) $r->bills,
                'total' => round((float) $r->total, 2),
            ])
            ->all();

        return count($rows) > 1 ? $rows : null;
    }
}