<?php

namespace App\Http\Controllers\Api;

use App\Exports\DashboardSalesReportExport;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

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

        // ?only=peak,customers  -> sirf wahi sections compute hote hain
        $only = collect(explode(',', (string) $request->input('only', '')))
            ->map(fn($v) => trim($v))
            ->filter()
            ->values()
            ->all();
        $want = fn(string $k) => empty($only) || in_array($k, $only, true);

        $sales = ($want('sales') || $want('profit'))
            ? $this->safe(fn() => $this->sales($storeId, $branchId, $from, $to, $prevFrom, $prevTo))
            : null;
        $expenses = ($want('expenses') || $want('profit'))
            ? $this->safe(fn() => $this->expenses($storeId, $branchId, $from, $to))
            : null;
        $profit = $want('profit')
            ? $this->safe(fn() => $this->profit($storeId, $branchId, $from, $to, $sales, $expenses))
            : null;

        return response()->json([
            'status' => true,
            'data' => [
                'range' => $range,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'sales' => $want('sales') ? $sales : null,
                'profit' => $profit,
                'expenses' => $want('expenses') ? $expenses : null,
                'payments' => $want('payments') ? $this->safe(fn() => $this->payments($storeId, $branchId, $from, $to)) : null,
                'trend' => $want('trend') ? $this->safe(fn() => $this->trend($storeId, $branchId)) : null,
                'trend_series' => $want('trend_series') ? $this->safe(fn() => $this->trendSeries($storeId, $branchId, (string) $request->input('series', '1w'))) : null,
                'peak' => $want('peak') ? $this->safe(fn() => $this->peak($storeId, $branchId)) : null,
                'top_profit' => $want('top_profit') ? $this->safe(fn() => $this->topProfit($storeId, $branchId)) : null,
                'stock_risk' => $want('stock_risk') ? $this->safe(fn() => $this->stockRisk($storeId, $branchId)) : null,
                'dead_stock' => $want('dead_stock') ? $this->safe(fn() => $this->deadStock($storeId, $branchId)) : null,
                'expiry' => $want('expiry') ? $this->safe(fn() => $this->expiry($storeId, $branchId)) : null,
                'customers' => $want('customers') ? $this->safe(fn() => $this->topCustomers($storeId, $branchId)) : null,
                'customers_due_count' => $want('customers_due_count') ? $this->safe(fn() => $this->dueCustomerCount($storeId, $branchId)) : null,
                'branches' => ($want('branches') && $user->role === 'admin')
                    ? $this->safe(fn() => $this->branchesSales($storeId, $from, $to))
                    : null,
            ],
        ]);
    }

    /* ------------------------------------------------------------ */
    /*  Sales report download                                        */
    /* ------------------------------------------------------------ */

    /**
     * GET /api/dashboard/sales-report
     *   ?format=pdf|xlsx|csv
     *   &from=YYYY-MM-DD&to=YYYY-MM-DD   (custom range, optional)
     *   &series=1d|1w|1m|1y              (from/to na ho to ye use hoga)
     *   &branch_id=
     */

    public function salesReport(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user && in_array($user->role, ['admin', 'manager'], true),
            403,
            'Not authorized.'
        );

        $storeId = (int) $user->store_id;

        $branchId = $this->resolveBranch(
            $user,
            (int) $request->input('branch_id', 0)
        );

        $format = in_array(
            $request->input('format'),
            ['pdf', 'xlsx', 'csv'],
            true
        )
            ? $request->input('format')
            : 'xlsx';

        [$from, $to, $periodText] = $this->reportPeriod($request);

        $days = $from->diffInDays($to) + 1;

        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        $totalColumn = $this->billTotalCol();

        // Current period summary
        $currentSummary = $this->bills(
            $storeId,
            $branchId,
            $from,
            $to
        )
            ->selectRaw(
                "COALESCE(SUM(sb.$totalColumn), 0) as total, COUNT(*) as bills"
            )
            ->first();

        $previousTotal = (float) $this->bills(
            $storeId,
            $branchId,
            $prevFrom,
            $prevTo
        )->sum("sb.$totalColumn");

        $totalSales = (float) ($currentSummary->total ?? 0);
        $totalBills = (int) ($currentSummary->bills ?? 0);

        $change = $previousTotal > 0
            ? round(
                (($totalSales - $previousTotal) / $previousTotal) * 100,
                1
            ) . '%'
            : 'n/a';

        // Payments and expenses
        $payments = $this->safe(
            fn() => $this->payments(
                $storeId,
                $branchId,
                $from,
                $to
            )
        ) ?? [
            'cash' => 0,
            'online' => 0,
        ];

        $expenses = $this->safe(
            fn() => $this->expenses(
                $storeId,
                $branchId,
                $from,
                $to
            )
        );

        // Branch name
        $branchName = $branchId
            ? (
                DB::table('branches')
                ->where('id', $branchId)
                ->value('name') ?? ''
            )
            : 'All branches';

        // Summary rows: [label, value, type]
        $summary = [
            ['Period', $periodText, 'text'],
            ['From', $from->format('d M Y'), 'text'],
            ['To', $to->format('d M Y'), 'text'],
            ['Branch', $branchName, 'text'],
            ['Total sales (INR)', $totalSales, 'money'],
            ['Bills', $totalBills, 'int'],
            [
                'Average bill (INR)',
                $totalBills
                    ? round($totalSales / $totalBills, 2)
                    : 0,
                'money',
            ],
            ['Previous period sales (INR)', $previousTotal, 'money'],
            ['Change vs previous period', $change, 'text'],
            ['Collected in cash (INR)', $payments['cash'] ?? 0, 'money'],
            [
                'Collected via UPI / bank (INR)',
                $payments['online'] ?? 0,
                'money',
            ],
            ['Expenses (INR)', $expenses['total'] ?? 0, 'money'],
            ['Generated on', now()->format('d M Y, h:i A'), 'text'],
        ];

        // Sales breakdown
        $breakdown = $this->reportBreakdown(
            $storeId,
            $branchId,
            $from,
            $to,
            $days
        );

        // PDF: maximum 500 bills; Excel/CSV: maximum 20,000
        $limit = $format === 'pdf' ? 500 : 20000;

        $reportData = $this->reportBills(
            $storeId,
            $branchId,
            $from,
            $to,
            $limit
        );

        $billRows = $reportData['rows'] ?? [];
        $billCount = (int) ($reportData['count'] ?? 0);

        // Ensure the view always receives a list of bill rows.
        if ($billRows instanceof \Illuminate\Support\Collection) {
            $billRows = $billRows->values()->all();
        } elseif ($billRows instanceof \Traversable) {
            $billRows = iterator_to_array($billRows);
        } elseif (! is_array($billRows)) {
            $billRows = [];
        }

        $stamp = $from->format('Ymd') . '-' . $to->format('Ymd');

        // PDF export
        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.dashboard-sales', [
                'periodText' => $periodText,
                'branchName' => $branchName,
                'summary' => $summary,
                'breakdown' => $breakdown,

                // Use a distinct name to avoid collision with other "bills" variables.
                'billRows' => $billRows,
                'billCount' => $billCount,

                'generatedAt' => now()->format('d M Y, h:i A'),
                'fmt' => fn($value) => '₹' . number_format(
                    (float) $value,
                    2
                ),
            ])->setPaper('a4', 'landscape');

            return $pdf->download("sales-report-$stamp.pdf");
        }

        // Excel / CSV export
        $export = new DashboardSalesReportExport(
            $summary,
            $breakdown,
            $billRows,
            $format === 'csv'
        );

        return $format === 'csv'
            ? Excel::download(
                $export,
                "sales-report-$stamp.csv",
                \Maatwebsite\Excel\Excel::CSV
            )
            : Excel::download(
                $export,
                "sales-report-$stamp.xlsx"
            );
    }

    /** Custom from/to ya series se period nikalta hai */
    private function reportPeriod(Request $request): array
    {
        $today = Carbon::today();
        $titles = ['1d' => 'Today', '1w' => 'Last 7 days', '1m' => 'Last 30 days', '1y' => 'Last 12 months'];

        $fromIn = $request->input('from');
        $toIn   = $request->input('to');

        if ($fromIn && $toIn) {
            try {
                $from = Carbon::parse($fromIn)->startOfDay();
                $to   = Carbon::parse($toIn)->startOfDay();
            } catch (\Throwable $e) {
                abort(422, 'Invalid date.');
            }

            if ($from->gt($to)) {
                [$from, $to] = [$to, $from];
            }
            if ($to->gt($today)) {
                $to = $today->copy();
            }
            if ($from->gt($to)) {
                $from = $to->copy();
            }
            abort_if($from->diffInDays($to) > 365, 422, 'Please choose a range of up to 1 year.');

            $text = $from->isSameDay($to)
                ? $from->format('d M Y')
                : $from->format('d M Y') . ' - ' . $to->format('d M Y');

            return [$from, $to, $text];
        }

        $series = in_array($request->input('series'), ['1d', '1w', '1m', '1y'], true)
            ? $request->input('series')
            : '1w';

        $from = match ($series) {
            '1d' => $today->copy(),
            '1w' => $today->copy()->subDays(6),
            '1m' => $today->copy()->subDays(29),
            default => $today->copy()->startOfMonth()->subMonths(11),
        };

        return [$from, $today->copy(), $titles[$series]];
    }

    /** Breakdown: 1 din = hourly, <= 92 din = daily, usse zyada = monthly */
    private function reportBreakdown($storeId, $branchId, Carbon $from, Carbon $to, int $days): array
    {
        $t = $this->billTotalCol();
        $mode = $days === 1 ? 'hour' : ($days <= 92 ? 'day' : 'month');

        $group = match ($mode) {
            'hour'  => 'HOUR(sb.created_at)',
            'day'   => 'DATE(sb.created_at)',
            default => "DATE_FORMAT(sb.created_at, '%Y-%m')",
        };

        $rows = $this->bills($storeId, $branchId, $from, $to)
            ->selectRaw("$group as k, COALESCE(SUM(sb.$t),0) as total, COUNT(*) as bills")
            ->groupBy(DB::raw($group))
            ->get()
            ->keyBy('k');

        $out = [];

        if ($mode === 'hour') {
            $nowHour = $to->isToday() ? (int) Carbon::now()->format('G') : 23;
            for ($h = 0; $h <= $nowHour; $h++) {
                $r = $rows[$h] ?? null;
                $out[] = [sprintf('%02d:00 - %02d:59', $h, $h), round((float) ($r->total ?? 0), 2), (int) ($r->bills ?? 0)];
            }
        } elseif ($mode === 'day') {
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $r = $rows[$d->toDateString()] ?? null;
                $out[] = [$d->format('d M Y'), round((float) ($r->total ?? 0), 2), (int) ($r->bills ?? 0)];
            }
        } else {
            for ($d = $from->copy()->startOfMonth(); $d->lte($to); $d->addMonth()) {
                $r = $rows[$d->format('Y-m')] ?? null;
                $out[] = [$d->format('M Y'), round((float) ($r->total ?? 0), 2), (int) ($r->bills ?? 0)];
            }
        }

        return $out;
    }

    /** Har bill ki ek row (keyed array). Return: ['rows' => [...], 'count' => total bills] */
    private function reportBills($storeId, $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        $t        = $this->billTotalCol();
        $numCol   = $this->col('sales_bills', ['bill_no', 'invoice_no', 'invoice_number', 'bill_number']);
        $custCol  = $this->col('sales_bills', ['customer_id']);
        $subCol   = $this->col('sales_bills', ['subtotal', 'sub_total', 'taxable_amount']);
        $taxCol   = $this->col('sales_bills', ['tax_amount', 'gst_amount', 'total_tax', 'tax_total']);
        $paidCol  = $this->col('sales_bills', ['paid_amount', 'amount_paid']);
        $dueCol   = $this->col('sales_bills', ['due_amount']);
        $payStCol = $this->col('sales_bills', ['payment_status']);
        $stCol    = $this->col('sales_bills', ['status', 'bill_status']);

        $count = (clone $this->bills($storeId, $branchId, $from, $to))->count();

        $q = $this->bills($storeId, $branchId, $from, $to)
            ->leftJoin('branches as b', 'b.id', '=', 'sb.branch_id');

        $select = ['sb.id', 'sb.created_at', "sb.$t as total", 'b.name as branch_name'];

        if ($numCol)   $select[] = "sb.$numCol as bill_no";
        if ($subCol)   $select[] = "sb.$subCol as subtotal";
        if ($taxCol)   $select[] = "sb.$taxCol as gst";
        if ($paidCol)  $select[] = "sb.$paidCol as paid";
        if ($dueCol)   $select[] = "sb.$dueCol as due";
        if ($payStCol) $select[] = "sb.$payStCol as payment_status";
        if ($stCol)    $select[] = "sb.$stCol as bill_status";

        if ($custCol && Schema::hasTable('customers')) {
            $q->leftJoin('customers as c', 'c.id', '=', "sb.$custCol");
            $select[] = 'c.name as customer_name';
            if (Schema::hasColumn('customers', 'mobile')) {
                $select[] = 'c.mobile as customer_mobile';
            }
        }

        $rows = $q->orderBy('sb.created_at')->limit($limit)->get($select);
        $ids  = $rows->pluck('id')->all();

        // Payment methods per bill
        $payMap = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            DB::table('sales_bill_payments')
                ->whereIn('sales_bill_id', $chunk)
                ->where('status', 'success')
                ->groupBy('sales_bill_id', 'method')
                ->selectRaw('sales_bill_id, LOWER(method) as m, SUM(amount) as a')
                ->get()
                ->each(function ($r) use (&$payMap) {
                    $payMap[$r->sales_bill_id][] = ucfirst($r->m) . ' ' . round((float) $r->a, 2);
                });
        }

        // COGS per bill (profit ke liye)
        $cogs = [];
        $im = $this->itemsMeta();
        if ($im) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $iq = DB::table("{$im['table']} as i")->whereIn("i.{$im['fk']}", $chunk);

                if ($im['cost']) {
                    $iq->selectRaw("i.{$im['fk']} as bid, SUM(i.{$im['qty']} * i.{$im['cost']}) as c");
                } else {
                    $iq->join('products as p', 'p.id', '=', "i.{$im['product']}")
                        ->selectRaw("i.{$im['fk']} as bid, SUM(i.{$im['qty']} * p.cost_price) as c");
                }

                foreach ($iq->groupBy("i.{$im['fk']}")->pluck('c', 'bid') as $bid => $c) {
                    $cogs[$bid] = (float) $c;
                }
            }
        }

        $out = $rows->map(function ($r) use ($payMap, $cogs) {
            $dt    = Carbon::parse($r->created_at);
            $total = (float) $r->total;
            $gst   = (float) ($r->gst ?? 0);
            $due   = (float) ($r->due ?? 0);
            $paid  = isset($r->paid) ? (float) $r->paid : max($total - $due, 0);
            $sub   = isset($r->subtotal) ? (float) $r->subtotal : $total - $gst;
            $profit = $total - $gst - ($cogs[$r->id] ?? 0);

            $payStatus = $r->payment_status
                ?? ($due <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'));

            return [
                'bill_no'        => $r->bill_no ?? ('#' . $r->id),
                'date'           => $dt->format('d M Y, h:i A'),
                'time'           => $dt->format('h:i A'),
                'customer'       => $r->customer_name ?? 'Walk-in',
                'mobile'         => $r->customer_mobile ?? '',
                'branch'         => $r->branch_name ?? '',
                'subtotal'       => round($sub, 2),
                'gst'            => round($gst, 2),
                'total'          => round($total, 2),
                'paid'           => round($paid, 2),
                'due'            => round($due, 2),
                'profit'         => round($profit, 2),
                'payment_status' => ucfirst((string) $payStatus),
                'bill_status'    => ucfirst((string) ($r->bill_status ?? 'completed')),
                'methods'        => implode(', ', $payMap[$r->id] ?? []),
            ];
        })->all();

        return ['rows' => $out, 'count' => $count];
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
            'product_batches',
            'stock_batches',
            'batches',
            'inventory_batches',
            'stocks',
            'inventories',
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
            'table'   => $table,
            'qty'     => $qty,
            'sold'    => in_array($qty, ['available_qty', 'current_stock'], true)
                ? null
                : $this->col($table, ['sold_qty', 'sold']),
            'product' => $product,
            'branch'  => $this->col($table, ['branch_id']),
            'expiry'  => $this->col($table, ['expiry_date', 'expiry', 'exp_date']),
        ];
    }

    private function availExpr(array $m): string
    {
        return $m['sold']
            ? "(s.{$m['qty']} - COALESCE(s.{$m['sold']}, 0))"
            : "s.{$m['qty']}";
    }

    private function scopeStore($q, array $m, int $storeId): void
    {
        if ($m['branch']) {
            $q->join('branches as sbr', 'sbr.id', '=', "s.{$m['branch']}")
                ->where('sbr.store_id', $storeId);
        } elseif (Schema::hasColumn($m['table'], 'store_id')) {
            $q->where('s.store_id', $storeId);
        } else {
            $q->join('products as sp', 'sp.id', '=', "s.{$m['product']}")
                ->where('sp.store_id', $storeId);
        }
    }

    /** [product_id => available qty] */
    private function stockByProduct(array $m, ?int $branchId, int $storeId)
    {
        $q = DB::table("{$m['table']} as s")
            ->selectRaw("s.{$m['product']} as pid, SUM(" . $this->availExpr($m) . ") as qty")
            ->groupBy("s.{$m['product']}");

        $this->scopeStore($q, $m, $storeId);

        if ($branchId && $m['branch']) {
            $q->where("s.{$m['branch']}", $branchId);
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

    /** Stock-style chart: 1d (hourly), 1w / 1m (daily), 1y (monthly) */
    private function trendSeries($storeId, $branchId, string $series): array
    {
        $series = in_array($series, ['1d', '1w', '1m', '1y'], true) ? $series : '1w';
        $t = $this->billTotalCol();
        $today = Carbon::today();
        $nowHour = (int) Carbon::now()->format('G');

        if ($series === '1d') {
            $from = $to = $today->copy();
            $prevFrom = $prevTo = $today->copy()->subDay();
            $group = 'HOUR(sb.created_at)';
        } elseif ($series === '1w') {
            $from = $today->copy()->subDays(6);
            $to = $today->copy();
            $prevFrom = $today->copy()->subDays(13);
            $prevTo = $today->copy()->subDays(7);
            $group = 'DATE(sb.created_at)';
        } elseif ($series === '1m') {
            $from = $today->copy()->subDays(29);
            $to = $today->copy();
            $prevFrom = $today->copy()->subDays(59);
            $prevTo = $today->copy()->subDays(30);
            $group = 'DATE(sb.created_at)';
        } else {
            $from = $today->copy()->startOfMonth()->subMonths(11);
            $to = $today->copy();
            $prevFrom = $from->copy()->subMonths(12);
            $prevTo = $from->copy()->subDay();
            $group = "DATE_FORMAT(sb.created_at, '%Y-%m')";
        }

        $rows = $this->bills($storeId, $branchId, $from, $to)
            ->selectRaw("$group as k, COALESCE(SUM(sb.$t),0) as total, COUNT(*) as bills")
            ->groupBy(DB::raw($group))
            ->get()
            ->keyBy('k');

        $points = [];

        if ($series === '1d') {
            for ($h = 0; $h < 24; $h++) {
                $r = $rows[$h] ?? null;
                $future = $h > $nowHour;
                $points[] = [
                    'k' => $h,
                    'total' => $future ? null : round((float) ($r->total ?? 0), 2),
                    'bills' => $future ? null : (int) ($r->bills ?? 0),
                ];
            }
        } elseif ($series === '1y') {
            for ($d = $from->copy(); $d->lte($today); $d->addMonth()) {
                $r = $rows[$d->format('Y-m')] ?? null;
                $points[] = [
                    'k' => $d->format('Y-m-01'),
                    'total' => round((float) ($r->total ?? 0), 2),
                    'bills' => (int) ($r->bills ?? 0),
                ];
            }
        } else {
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $r = $rows[$d->toDateString()] ?? null;
                $points[] = [
                    'k' => $d->toDateString(),
                    'total' => round((float) ($r->total ?? 0), 2),
                    'bills' => (int) ($r->bills ?? 0),
                ];
            }
        }

        $prevQuery = $this->bills($storeId, $branchId, $prevFrom, $prevTo);
        if ($series === '1d') {
            // yesterday up to the same hour, so the comparison is fair
            $prevQuery->whereRaw('HOUR(sb.created_at) <= ?', [$nowHour]);
        }

        return [
            'series' => $series,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'points' => $points,
            'total' => round((float) collect($points)->sum('total'), 2),
            'prev_total' => round((float) $prevQuery->sum("sb.$t"), 2),
        ];
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
            ->map(fn($r) => ['hour' => (int) $r->h, 'bills' => (int) $r->bills])
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
            ->map(fn($r) => [
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

        $stock = $this->stockByProduct($sm, $branchId, $storeId);
        $limit = self::LOW_STOCK_LIMIT;

        $out = $stock->filter(fn($q) => (float) $q <= 0)->count();
        $low = $stock->filter(fn($q) => (float) $q > 0 && (float) $q <= $limit)->count();

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

       $stock = $this->stockByProduct($sm, $branchId, $storeId)
    ->filter(fn($q, $pid) => (float) $q > 0 && ! in_array($pid, $soldIds, false));

        if ($stock->isEmpty()) {
            return ['count' => 0, 'value' => 0, 'items' => []];
        }

        $products = DB::table('products')
            ->where('store_id', $storeId)
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

        $e = "s.{$sm['expiry']}";

        $base = DB::table("{$sm['table']} as s")
            ->whereRaw($this->availExpr($sm) . ' > 0')
            ->whereNotNull($e);

        $this->scopeStore($base, $sm, $storeId);

        if ($branchId && $sm['branch']) {
            $base->where("s.{$sm['branch']}", $branchId);
        }

        $today = Carbon::today()->toDateString();
        $soon  = Carbon::today()->addDays(30)->toDateString();

        return [
            'expired' => (clone $base)->whereDate($e, '<', $today)->count(),
            'soon'    => (clone $base)->whereDate($e, '>=', $today)->whereDate($e, '<=', $soon)->count(),
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
            ->map(fn($r) => [
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
            ->map(fn($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'bills' => (int) $r->bills,
                'total' => round((float) $r->total, 2),
            ])
            ->all();

        return count($rows) > 1 ? $rows : null;
    }
}
