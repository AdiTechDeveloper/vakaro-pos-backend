<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardMoneyController extends Controller
{
    // Which payment methods count as cash and which as bank.
    private const CASH_METHODS = ['cash'];
    private const BANK_METHODS = ['online', 'bank', 'upi', 'card'];

    public function summary(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $branchId = $this->resolveBranch($user, (int) $request->input('branch_id', 0));

        // ---- Money IN: sales payments (wallet is not cash/bank, so it is ignored) ----
        $sales = DB::table('sales_bill_payments as sbp')
            ->join('sales_bills as sb', 'sb.id', '=', 'sbp.sales_bill_id')
            ->where('sb.store_id', $user->store_id)
            ->where('sbp.status', 'success');
        if ($branchId) {
            $sales->where('sb.branch_id', $branchId);
        }
        $salesByMethod = $sales
            ->groupBy('sbp.method')
            ->selectRaw('LOWER(sbp.method) as method, SUM(sbp.amount) as total')
            ->pluck('total', 'method');

        // ---- Money IN: customer advance deposits ----
        $deposits = DB::table('customer_advance_deposits as d')
            ->join('branches as b', 'b.id', '=', 'd.branch_id')
            ->where('b.store_id', $user->store_id);
        if ($branchId) {
            $deposits->where('d.branch_id', $branchId);
        }
        $depositByMethod = $deposits
            ->groupBy('d.method')
            ->selectRaw('LOWER(d.method) as method, SUM(d.amount) as total')
            ->pluck('total', 'method');

        // ---- Money OUT: payments made to suppliers ----
        $pp = DB::table('purchase_payments as pp')
            ->join('purchase_bills as pb', 'pb.id', '=', 'pp.purchase_bill_id')
            ->where('pb.store_id', $user->store_id)
            ->whereNull('pb.deleted_at')
            ->where('pp.status', 'success');
        if ($branchId) {
            $pp->where('pb.branch_id', $branchId);
        }
        $paidByMethod = $pp
            ->groupBy('pp.method')
            ->selectRaw('LOWER(pp.method) as method, SUM(pp.amount) as total')
            ->pluck('total', 'method');

        // ---- Money OUT: expenses (soft-deleted ignore) ----
        $exp = DB::table('expenses')
            ->where('store_id', $user->store_id)
            ->whereNull('deleted_at');
        if ($branchId) {
            $exp->where('branch_id', $branchId);
        }
        $expenseByMethod = $exp
            ->groupBy('payment_mode')
            ->selectRaw('LOWER(payment_mode) as method, SUM(amount) as total')
            ->pluck('total', 'method');

        $sum = fn($rows, array $keys) => (float) collect($keys)
            ->sum(fn($k) => (float) ($rows[$k] ?? 0));

        $cashIn  = $sum($salesByMethod, self::CASH_METHODS) + $sum($depositByMethod, self::CASH_METHODS);
        $bankIn  = $sum($salesByMethod, self::BANK_METHODS) + $sum($depositByMethod, self::BANK_METHODS);
        $cashOut = $sum($paidByMethod, self::CASH_METHODS) + $sum($expenseByMethod, self::CASH_METHODS);
        $bankOut = $sum($paidByMethod, self::BANK_METHODS) + $sum($expenseByMethod, self::BANK_METHODS);
        // ---- Customer dues ----
        $custDue = DB::table('sales_bills')
            ->where('store_id', $user->store_id)
            ->where('due_amount', '>', 0);
        if ($branchId) {
            $custDue->where('branch_id', $branchId);
        }

        // ---- Supplier dues ----
        $bills = DB::table('purchase_bills')
            ->where('store_id', $user->store_id)
            ->whereNull('deleted_at');
        $paid = DB::table('purchase_payments as pp')
            ->join('purchase_bills as pb', 'pb.id', '=', 'pp.purchase_bill_id')
            ->where('pb.store_id', $user->store_id)
            ->whereNull('pb.deleted_at')
            ->where('pp.status', 'success');
        if ($branchId) {
            $bills->where('branch_id', $branchId);
            $paid->where('pb.branch_id', $branchId);
        }
        $supplierDue = max(0, (float) $bills->sum('total_amount') - (float) $paid->sum('pp.amount'));

        return response()->json([
            'status' => true,
            'data' => [
                'cash_in_hand' => round($cashIn - $cashOut, 2),
                'bank_balance' => round($bankIn - $bankOut, 2),
                'customer_dues' => round((float) $custDue->sum('due_amount'), 2),
                'supplier_dues' => round($supplierDue, 2),
                // Temporary, for checking the numbers. Remove after verifying.
                'debug' => [
                    'cash_in' => round($cashIn, 2),
                    'cash_out' => round($cashOut, 2),
                    'bank_in' => round($bankIn, 2),
                    'bank_out' => round($bankOut, 2),
                    'sales_by_method' => $salesByMethod,
                    'deposit_by_method' => $depositByMethod,
                    'paid_by_method' => $paidByMethod,
                    'expense_by_method' => $expenseByMethod,
                ],
            ],
        ]);
    }

    private function resolveBranch($user, int $requested): ?int
    {
        if ($user->role === 'manager') {
            $id = !empty($user->branch_id)
                ? (int) $user->branch_id
                : DB::table('branch_staff')->where('user_id', $user->id)->value('branch_id');
            abort_if(!$id, 403, 'Manager ki assigned branch resolve nahi hui.');
            return (int) $id;
        }

        if ($requested > 0) {
            $ok = DB::table('branches')
                ->where('id', $requested)
                ->where('store_id', $user->store_id)
                ->exists();
            abort_if(!$ok, 403, 'Invalid branch access.');
            return $requested;
        }

        return null;
    }
}
