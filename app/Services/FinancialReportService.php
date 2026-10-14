<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    /**
     * $scope = [
     *   'store_id'   => int,
     *   'branch_ids' => int[],
     *   'from'       => Carbon,
     *   'to'         => Carbon,
     *   'branch_id'  => int|null
     * ]
     */
    public function getFinancialReport(array $scope)
    {
        $storeId = (int) $scope['store_id'];
        $branchIds = $scope['branch_ids'] ?? [];
        $from = $scope['from'];
        $to = $scope['to'];

        $denied = empty($storeId) || empty($branchIds);

        $salesBillBase = DB::table('sales_bills')
            ->whereBetween('sales_bills.created_at', [$from, $to])
            ->where('sales_bills.store_id', $storeId)
            ->whereIn('sales_bills.branch_id', $branchIds)
            ->when($denied, fn ($q) => $q->whereRaw('1 = 0'));

        $salesBillLinesBase = DB::table('sales_bill_lines')
            ->join('sales_bills as sb_scope', 'sb_scope.id', '=', 'sales_bill_lines.sales_bill_id')
            ->whereBetween('sales_bill_lines.created_at', [$from, $to])
            ->where('sb_scope.store_id', $storeId)
            ->whereIn('sb_scope.branch_id', $branchIds)
            ->when($denied, fn ($q) => $q->whereRaw('1 = 0'));

        // Sales KPIs
        $sales = (clone $salesBillBase)
            ->selectRaw('
                COUNT(*) as total_bills,
                SUM(subtotal) as total_sales,
                SUM(total_gst) as total_tax,
                SUM(total_amount) as grand_total,
                SUM(paid_amount) as received_amount,
                SUM(due_amount) as pending_amount
            ')->first();

        // Purchase KPIs
        $purchase = DB::table('purchase_bills')
            ->whereBetween('created_at', [$from, $to])
            ->where('store_id', $storeId)
            ->whereIn('branch_id', $branchIds)
            ->when($denied, fn ($q) => $q->whereRaw('1 = 0'))
            ->selectRaw('
                COUNT(*) as total_purchase_bills,
                SUM(total_amount) as total_purchase
            ')->first();

        $profit = ($sales->grand_total ?? 0) - ($purchase->total_purchase ?? 0);

        // GST splits
        $gst = (clone $salesBillLinesBase)
            ->selectRaw('
                SUM(sales_bill_lines.cgst) as total_cgst,
                SUM(sales_bill_lines.sgst) as total_sgst,
                SUM(sales_bill_lines.igst) as total_igst
            ')->first();

        // Daily sales trend
        $daily = (clone $salesBillBase)
            ->selectRaw('
                DATE(sales_bills.created_at) as date,
                SUM(total_amount) as sales,
                SUM(paid_amount) as received,
                SUM(due_amount) as due
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top 5 products
        $topProducts = (clone $salesBillLinesBase)
            ->join('products', 'products.id', '=', 'sales_bill_lines.product_id')
            ->selectRaw('
                products.name,
                SUM(sales_bill_lines.qty) as total_qty,
                SUM(sales_bill_lines.amount) as total_sales
            ')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        // Customer dues
        $dues = (clone $salesBillBase)
            ->join('customers', 'customers.id', '=', 'sales_bills.customer_id')
            ->where('sales_bills.due_amount', '>', 0)
            ->selectRaw('
                customers.name,
                SUM(sales_bills.due_amount) as total_due
            ')
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('total_due')
            ->get();

        return [
            'filters' => [
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
                'branch_id' => $scope['branch_id'] ?? null,
            ],

            'kpis' => [
                'total_sales' => (float) ($sales->grand_total ?? 0),
                'total_purchase' => (float) ($purchase->total_purchase ?? 0),
                'profit' => (float) $profit,
                'received_amount' => (float) ($sales->received_amount ?? 0),
                'pending_amount' => (float) ($sales->pending_amount ?? 0),
            ],

            'gst' => [
                'cgst' => (float) ($gst->total_cgst ?? 0),
                'sgst' => (float) ($gst->total_sgst ?? 0),
                'igst' => (float) ($gst->total_igst ?? 0),
            ],

            'charts' => [
                'daily_sales' => $daily,
            ],

            'top_products' => $topProducts,
            'customer_dues' => $dues,
        ];
    }
}
