<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\PurchaseBill;
use App\Models\PurchasePayment;
use App\Models\PurchaseLine;
use Illuminate\Support\Facades\DB;


class SupplierController extends Controller
{
    // public function index()
    // {
    //     $storeId = Auth::user()->store_id;
    //     $suppliers = Supplier::where('store_id', $storeId)->with(['store'])->get();

    //     return response()->json([
    //         'status' => true,
    //         'suppliers' => $suppliers
    //     ], 200);
    // }

    public function index()
    {
        $user = Auth::user();

        if (!in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $branchIds = null;

        if ($user->role === 'manager') {
            $branchIds = $user->branches()->pluck('branches.id')->toArray();
        }

        $suppliers = Supplier::where('store_id', $user->store_id)
            ->with('store')
            ->get()
            ->map(function ($supplier) use ($user, $branchIds) {
                $billsQuery = PurchaseBill::where('store_id', $user->store_id)
                    ->where('supplier_id', $supplier->id);

                if ($branchIds !== null) {
                    $billsQuery->whereIn('branch_id', $branchIds);
                }

                $bills = $billsQuery->get(['id', 'total_amount']);

                $billIds = $bills->pluck('id');

                $totalPurchase = (float) $bills->sum('total_amount');

                $totalPaid = $billIds->isEmpty()
                    ? 0
                    : (float) PurchasePayment::whereIn('purchase_bill_id', $billIds)
                        ->where('status', 'success')
                        ->sum('amount');

                $supplier->total_purchase = round($totalPurchase, 2);
                $supplier->total_paid = round($totalPaid, 2);
                $supplier->outstanding_due = round(
                    max(0, $totalPurchase - $totalPaid),
                    2
                );
                $supplier->purchase_bill_count = $bills->count();

                return $supplier;
            });

        return response()->json([
            'status' => true,
            'suppliers' => $suppliers,
        ], 200);
    }


    public function show($id)
    {
        $storeId = Auth::user()->store_id;
        $supplier = Supplier::where('store_id', $storeId)->where('id', $id)->first();

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'Supplier not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'supplier' => $supplier
        ], 200);
    }
    public function purchaseHistory($id)
    {
        $user = Auth::user();

        if (!in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $supplier = Supplier::where('store_id', $user->store_id)
            ->where('id', $id)
            ->first();

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'Supplier not found',
            ], 404);
        }

        $query = PurchaseBill::with([
            'branch:id,name',
            'payments' => fn($q) => $q->where('status', 'success'),
            'lines.product:id,name,sku',
        ])
            ->where('store_id', $user->store_id)
            ->where('supplier_id', $supplier->id);

        if ($user->role === 'manager') {
            $branchIds = $user->branches()->pluck('branches.id')->toArray();
            $query->whereIn('branch_id', $branchIds);
        }

        $bills = $query->orderByDesc('bill_date')->get();

        $history = $bills->map(function ($bill) {
            $paid = (float) $bill->payments->sum('amount');
            $total = (float) $bill->total_amount;

            return [
                'id' => $bill->id,
                'bill_no' => $bill->bill_no,
                'bill_date' => $bill->bill_date,
                'branch' => $bill->branch?->name,
                'total_amount' => round($total, 2),
                'paid_amount' => round($paid, 2),
                'due_amount' => round(max(0, $total - $paid), 2),
                'products' => $bill->lines->map(fn($line) => [
                    'product_id' => $line->product_id,
                    'product_name' => $line->product?->name ?? 'Deleted product',
                    'qty' => $line->qty,
                    'purchase_rate' => $line->purchase_rate,
                ])->values(),
            ];
        });

        return response()->json([
            'status' => true,
            'supplier' => $supplier,
            'purchase_history' => $history,
        ]);
    }

public function rateComparison(Request $request)
{
    $user = Auth::user();

    if (!in_array($user->role, ['admin', 'manager'], true)) {
        return response()->json([
            'status' => false,
            'message' => 'Unauthorized',
        ], 403);
    }

    $validated = $request->validate([
        'product_id' => 'nullable|integer',
        'supplier_id' => 'nullable|integer',
        'date_from' => 'nullable|date',
        'date_to' => 'nullable|date|after_or_equal:date_from',
    ]);

    $query = PurchaseLine::query()
        ->join('purchase_bills', 'purchase_lines.purchase_bill_id', '=', 'purchase_bills.id')
        ->join('products', 'purchase_lines.product_id', '=', 'products.id')
        ->join('suppliers', 'purchase_bills.supplier_id', '=', 'suppliers.id')
        ->where('purchase_bills.store_id', $user->store_id)
        ->whereNull('purchase_bills.deleted_at')
        ->where('suppliers.store_id', $user->store_id)
        ->select([
            'purchase_lines.product_id',
            'products.name as product_name',
            'purchase_bills.supplier_id',
            'suppliers.name as supplier_name',
            'purchase_lines.purchase_rate',
            'purchase_lines.qty',
            'purchase_bills.bill_no',
            'purchase_bills.bill_date',
            'purchase_bills.branch_id',
        ]);

    if ($user->role === 'manager') {
        $branchIds = $user->branches()
            ->pluck('branches.id')
            ->toArray();

        $query->whereIn('purchase_bills.branch_id', $branchIds);
    }

    if (!empty($validated['product_id'])) {
        $query->where(
            'purchase_lines.product_id',
            $validated['product_id']
        );
    }

    if (!empty($validated['supplier_id'])) {
        $query->where(
            'purchase_bills.supplier_id',
            $validated['supplier_id']
        );
    }

    if (!empty($validated['date_from'])) {
        $query->whereDate(
            'purchase_bills.bill_date',
            '>=',
            $validated['date_from']
        );
    }

    if (!empty($validated['date_to'])) {
        $query->whereDate(
            'purchase_bills.bill_date',
            '<=',
            $validated['date_to']
        );
    }

    $lines = $query
        ->orderByDesc('purchase_bills.bill_date')
        ->orderByDesc('purchase_bills.id')
        ->get();

    $products = $lines
        ->groupBy('product_id')
        ->map(function ($productLines, $productId) {
            $supplierRates = $productLines
                ->groupBy('supplier_id')
                ->map(function ($supplierLines, $supplierId) {
                    $latest = $supplierLines->first();
                    $rates = $supplierLines
                        ->pluck('purchase_rate')
                        ->map(fn ($rate) => (float) $rate);

                    return [
                        'supplier_id' => (int) $supplierId,
                        'supplier_name' => $latest->supplier_name,
                        'latest_rate' => round(
                            (float) $latest->purchase_rate,
                            2
                        ),
                        'lowest_rate' => round($rates->min(), 2),
                        'highest_rate' => round($rates->max(), 2),
                        'average_rate' => round($rates->avg(), 2),
                        'last_purchase_date' => $latest->bill_date,
                        'last_bill_no' => $latest->bill_no,
                        'purchase_count' => $supplierLines->count(),
                    ];
                })
                ->sortBy('latest_rate')
                ->values();

            return [
                'product_id' => (int) $productId,
                'product_name' => $productLines->first()->product_name,
                'lowest_current_supplier' => $supplierRates->first(),
                'supplier_rates' => $supplierRates,
            ];
        })
        ->values();

    return response()->json([
        'status' => true,
        'filters' => $validated,
        'products' => $products,
    ]);
}

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                // 'gstin' => 'required|string|max:15',
                // 'contact' => 'required|string|max:20',
                // 'address' => 'required|string',
                'state' => 'required|string|max:20'
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $supplier = Supplier::create([
                'store_id' => $storeId,
                'name' => $request->name,
                'gstin' => $request->gstin,
                'contact' => $request->contact,
                'address' => $request->address,
                'state' => $request->state,
                'created_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Supplier created successfully',
                'supplier' => $supplier
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the supplier.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                // 'gstin' => 'required|string|max:15',
                // 'contact' => 'required|string|max:20',
                // 'address' => 'required|string',
                'state' => 'required|string|max:20',
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $supplier = Supplier::where('store_id', $storeId)->where('id', $id)->first();

            if (!$supplier) {
                return response()->json([
                    'status' => false,
                    'message' => 'Supplier not found'
                ], 404);
            }

            $supplier->update([
                'name' => $request->name,
                'gstin' => $request->gstin,
                'contact' => $request->contact,
                'address' => $request->address,
                'state' => $request->state,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Supplier updated successfully',
                'supplier' => $supplier
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating the supplier.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $supplier = Supplier::where('store_id', $storeId)->where('id', $id)->first();

            if (!$supplier) {
                return response()->json([
                    'status' => false,
                    'message' => 'Supplier not found'
                ], 404);
            }
            $hasPurchaseHistory = PurchaseBill::where('store_id', $storeId)
                ->where('supplier_id', $supplier->id)
                ->exists();

            if ($hasPurchaseHistory) {
                return response()->json([
                    'status' => false,
                    'message' => 'Supplier cannot be deleted because purchase history exists.',
                ], 409);
            }
            $supplier->delete();

            return response()->json([
                'status' => true,
                'message' => 'Supplier deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting the supplier.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
