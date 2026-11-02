<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\GstRate;
use App\Models\Inventory;
use App\Models\ItcEntry;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\PurchaseLine;
use App\Models\PurchasePayment;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\FeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseBillController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = Auth::user();

            $query = PurchaseBill::with([
                'store',
                'supplier',
                'branch:id,name',
                'supplier:id,name',
                'lines.product:id,name,sku',
                'lines.inventory',
            ]);

            if ($user->role === 'manager') {
                $managerBranchIds = $user->branches()->pluck('branches.id');
                $query->whereIn('branch_id', $managerBranchIds);
            }

            if ($user->role === 'admin') {
                $query->whereHas('branch', function ($q) use ($user) {
                    $q->where('store_id', $user->store_id);
                });
            }

            if ($request->filled('search')) {
                $term = trim($request->query('search'));
                $query->where(function ($q) use ($term) {
                    $q->where('bill_no', 'like', "%{$term}%")
                        ->orWhere('inward_no', 'like', "%{$term}%")
                        ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$term}%"));
                });
            }

            $query->orderBy('id', 'DESC');

            if ($request->filled('page') || $request->filled('per_page')) {
                $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
                $p = $query->paginate($perPage);

                return response()->json([
                    'status' => true,
                    'data' => $p->items(),
                    'meta' => [
                        'total' => $p->total(),
                        'current_page' => $p->currentPage(),
                        'per_page' => $p->perPage(),
                        'last_page' => $p->lastPage(),
                    ],
                ], 200);
            }

            return response()->json([
                'status' => true,
                'data' => $query->get(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id)
    {
        try {
            $user = Auth::user();

            $bill = PurchaseBill::with([
                'branch:id,name',
                'supplier:id,name',
                'lines.product:id,name,sku',
                'lines.inventory',
                'payments' => fn ($query) => $query->where('status', 'success'),
            ])->find($id);

            if (! $bill) {
                return response()->json([
                    'status' => false,
                    'message' => 'Purchase bill not found',
                ], 404);
            }

            if ($user->role === 'admin' && $bill->store_id != $user->store_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized - Admin can only access purchase bills of their own store',
                ], 403);
            }

            if ($user->role === 'manager') {
                if ($bill->store_id != $user->store_id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - Manager can access only their store purchase bills',
                    ], 403);
                }

                $managerBranchIds = $user->branches()->pluck('branches.id');

                if (! $managerBranchIds->contains($bill->branch_id)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - Manager can access purchase bills of their assigned branch only',
                    ], 403);
                }
            }

            if (! in_array($user->role, ['admin', 'manager'], true)) {
                return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
            }

            $totalPaid = round((float) PurchasePayment::where('purchase_bill_id', $bill->id)
                ->where('status', 'success')
                ->sum('amount'), 2);
            $totalAmount = round((float) $bill->total_amount, 2);

            $dueAmount = max(0, round($totalAmount - $totalPaid, 2));

            // Payment status
            $status = 'due';

            if ($totalPaid == 0) {
                $status = 'due';
            } elseif ($totalPaid < $totalAmount) {
                $status = 'partial';
            } else {
                $status = 'paid';
            }

            // Set the response values explicitly so reversed ledger rows cannot be counted.
            $bill->setAttribute('paid_amount', $totalPaid);
            $bill->setAttribute('due_amount', $dueAmount);
            $bill->payment_status = $status;

            if ($user->role === 'admin') {
                return response()->json(['status' => true, 'data' => $bill]);
            }

            if ($user->role === 'manager') {
                return response()->json(['status' => true, 'data' => $bill]);
            }

            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function isPharmacyStore($storeId): bool
    {
        return collect(FeatureService::storeFeatures($storeId))->contains('pharmacy');
    }

    public function generateBatchNo(int $productId)
    {
        $date = now()->format('Ymd');

        $lastBatch = Inventory::where('product_id', $productId)
            ->whereDate('created_at', now())
            ->where('batch_no', 'like', "POS-{$productId}-{$date}-%")
            ->orderByDesc('id')
            ->first();

        if ($lastBatch) {
            $lastNumber = (int) substr($lastBatch->batch_no, -3);
            $nextNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $nextNumber = '001';
        }

        return "POS-{$productId}-{$date}-{$nextNumber}";
    }

    protected function generateInwardNumber($branchId)
    {
        $user = Auth::user();
        $storeId = $user->store_id;

        $lastBill = PurchaseBill::withTrashed()
            ->where('store_id', $storeId)
            ->where('branch_id', $branchId)
            ->orderBy('id', 'desc')
            ->first();

        $sequence = 1;
        if ($lastBill && $lastBill->inward_sequence) {
            $sequence = (int) $lastBill->inward_sequence + 1;
        }

        $financialYear = '2627';
        $formattedSeq = str_pad($sequence, 4, '0', STR_PAD_LEFT);

        $inwardNo = "INW/{$storeId}/{$branchId}/{$financialYear}/{$formattedSeq}";

        return [
            'inward_no' => $inwardNo,
            'inward_sequence' => $sequence,
        ];
    }

    private function lineMessages(): array
    {
        return [
            'lines.*.batch_no.required' => 'Line :position: Batch number is required.',
            'lines.*.batch_no.max' => 'Line :position: Batch number can be at most 50 characters.',
            'lines.*.expiry_date.required' => 'Line :position: Expiry date is required.',
            'lines.*.expiry_date.date' => 'Line :position: Expiry date is not valid.',
            'lines.*.expiry_date.after' => 'Line :position: Expiry date must be a future date. Expired medicines cannot be purchased.',
        ];
    }

    public function store(Request $request)
    {
        $isPharmacy = $this->isPharmacyStore(Auth::user()->store_id);

        $validated = $request->validate([
            'branch_id' => 'required|integer',
            'supplier_id' => 'required|integer',
            'bill_no' => [
                'required',
                'string',
                \Illuminate\Validation\Rule::unique('purchase_bills')->where(
                    fn ($q) => $q->where('supplier_id', $request->supplier_id)
                ),
            ],
            'bill_date' => 'required|date',
            'is_lost' => 'sometimes|in:0,1',

            // New Global Fields
            'tax_type' => 'required|string|in:inclusive,exclusive',
            'settlement_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',

            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.free_qty' => 'nullable|numeric|min:0',
            'lines.*.purchase_rate' => 'required|numeric|min:0',
            'lines.*.mrp' => 'required|numeric|min:0',
            'lines.*.selling_price' => 'required|numeric|min:0',
            'lines.*.gst_rate_id' => 'required|integer',
            'lines.*.batch_no' => $isPharmacy ? 'required|string|max:50' : 'nullable|string',
            'lines.*.expiry_date' => $isPharmacy ? 'required|date|after:today' : 'nullable|date',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'lines.*.discount_type' => 'nullable|string|in:percent,fixed',
            'lines.*.hsn_code' => 'nullable|string',
            'lines.*.is_opening' => 'sometimes|in:0,1',

            'payments' => 'nullable|array',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.method' => 'required_with:payments|string|in:cash,online,bank',
            'payments.*.reference' => 'nullable|string|max:255',
            'payments.*.payment_date' => 'nullable|date',
        ], $this->lineMessages());

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $storeId = $user->store_id;
            $branchId = (int) $request->branch_id;
            $supplier = Supplier::where('store_id', $storeId)->find($validated['supplier_id']);
            $branch = Branch::where('store_id', $storeId)->find($validated['branch_id']);

            if (! $supplier || ! $branch) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Invalid supplier or branch for this store.',
                ], 422);
            }

            if ($user->role === 'manager') {
                $allowedBranchIds = $user->branches()->pluck('branches.id')->toArray();
                if (! in_array((int) $validated['branch_id'], $allowedBranchIds, true)) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - You are not assigned to this branch.',
                    ], 403);
                }
            }

            // Generate Inward Details
            $inwardData = $this->generateInwardNumber($validated['branch_id']);

            $originState = ($user->role === 'admin')
                ? Store::findOrFail($storeId)->state
                : $branch->state;
            $isIntra = ($originState === $supplier->state);

            $taxType = $validated['tax_type']; // 'inclusive' or 'exclusive'

            // Initial Bill Creation with New Fields Included
            $bill = PurchaseBill::create([
                'store_id' => $storeId,
                'branch_id' => $validated['branch_id'],
                'supplier_id' => $validated['supplier_id'],
                'inward_no' => $inwardData['inward_no'],
                'inward_sequence' => $inwardData['inward_sequence'],
                'bill_no' => $validated['bill_no'],
                'bill_date' => $validated['bill_date'],
                'is_lost' => $validated['is_lost'] ?? 0,
                'tax_type' => $taxType,
                'settlement_amount' => $validated['settlement_amount'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $totals = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];

            foreach ($validated['lines'] as $lineData) {
                $product = Product::where('store_id', $storeId)->find($lineData['product_id']);
                $gst = GstRate::where('store_id', $storeId)->find($lineData['gst_rate_id']);

                if (! $product || ! $gst) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid product or GST rate for this store.',
                    ], 422);
                }

                $qty = (float) $lineData['qty'];
                $freeQty = (float) ($lineData['free_qty'] ?? 0);
                $enteredPurchaseRate = (float) $lineData['purchase_rate'];
                $mrp = (float) $lineData['mrp'];
                $sellingPrice = (float) $lineData['selling_price'];
                if ($product->sale_type === 'weight' && $product->price_per_kg) {
                    $sellingPrice = (float) $product->price_per_kg;
                }
                $taxRate = (float) $gst->rate;

                if ($isPharmacy && $sellingPrice > $mrp) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => "Selling price can not more than MRP: {$product->name}",
                    ], 422);
                }

                $discount = isset($lineData['discount']) ? (float) $lineData['discount'] : 0.0;
                $discountType = $lineData['discount_type'] ?? null;

                // Tax Handling Core Logic
                if ($taxType === 'inclusive') {
                    $calculatedBaseRate = $enteredPurchaseRate / (1 + ($taxRate / 100));

                    $grossValue = round($qty * $calculatedBaseRate, 2);
                    $discountAmount = ($discountType === 'percent')
                        ? round($grossValue * ($discount / 100), 2)
                        : round($discount, 2);

                    $taxable = max(0, round($grossValue - $discountAmount, 2));

                    // Calculate taxes straight out of the computed taxable base
                    $cgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
                    $sgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
                    $igst = ! $isIntra ? round(($taxable * $taxRate) / 100, 2) : 0;

                    // Inventory value should represent the net raw cost before tax
                    $inventoryCostPrice = round($calculatedBaseRate, 4);
                } else {
                    // Standard Tax Exclusive Logic
                    $grossValue = round($qty * $enteredPurchaseRate, 2);
                    $discountAmount = ($discountType === 'percent')
                        ? round($grossValue * ($discount / 100), 2)
                        : round($discount, 2);

                    $taxable = max(0, round($grossValue - $discountAmount, 2));

                    $cgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
                    $sgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
                    $igst = ! $isIntra ? round(($taxable * $taxRate) / 100, 2) : 0;

                    $inventoryCostPrice = $enteredPurchaseRate;
                }

                $rawBatch = isset($lineData['batch_no']) ? trim($lineData['batch_no']) : '';
                if ($isPharmacy) {
                    $rawBatch = strtoupper($rawBatch);
                }
                $hasBatch = $rawBatch !== '';
                $batchNo = $hasBatch ? $rawBatch : $this->generateBatchNo($product->id);

                // Purchase Line Item Insertion
                $purchaseLine = PurchaseLine::create([
                    'purchase_bill_id' => $bill->id,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'free_qty' => $freeQty,
                    'purchase_rate' => $enteredPurchaseRate,
                    'mrp' => $mrp,
                    'selling_price' => $sellingPrice,
                    'taxable_value' => $taxable,
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'gst_rate_id' => $lineData['gst_rate_id'],
                    'discount_type' => $discountType,
                    'discount' => $discount,
                    'hsn_code' => $lineData['hsn_code'] ?? $product->hsn_code,
                    'batch_no' => $batchNo,
                    'expiry_date' => $lineData['expiry_date'] ?? null,
                    'is_opening' => (int) ($lineData['is_opening'] ?? 0),
                ]);

                // Setup or link Barcodes safely
                $existingBatch = Inventory::where('product_id', $product->id)
                    ->where('branch_id', $branchId)
                    ->where('selling_price', $sellingPrice)
                    ->where('mrp', $mrp)
                    ->where('batch_no', $batchNo)
                    ->where('cost_price', $inventoryCostPrice)
                    ->where('free', 0)
                    ->when($isPharmacy, fn ($q) => $q->whereDate('expiry_date', $purchaseLine['expiry_date']))
                    ->first();

                if ($existingBatch) {
                    $existingBatch->increment('qty', $qty);
                    $existingBatch->amount = $existingBatch->qty * $existingBatch->cost_price;
                    // Re-point this batch at the current line so future edits can find it again
                    $existingBatch->purchase_line_id = $purchaseLine->id;
                    $existingBatch->save();
                    $batchBarcode = $existingBatch->batch_barcode;
                } else {
                    $batchBarcode = $product->barcode;
                    if (Inventory::where('batch_barcode', $batchBarcode)->exists()) {
                        $batchBarcode = $product->barcode.'-'.strtoupper(Str::random(4));
                    }

                    Inventory::create([
                        'product_id' => $product->id,
                        'branch_id' => $branchId,
                        'purchase_bill_id' => $bill->id,
                        'purchase_line_id' => $purchaseLine->id,
                        'batch_barcode' => $batchBarcode,
                        'batch_no' => $batchNo,
                        'mrp' => $mrp,
                        'selling_price' => $sellingPrice,
                        'cost_price' => $inventoryCostPrice,
                        'qty' => $qty,
                        'sold_qty' => 0,
                        'free' => 0,
                        'rate' => $inventoryCostPrice,
                        'amount' => $taxable,
                        'expiry_date' => $lineData['expiry_date'] ?? null,
                        'is_opening' => (int) ($lineData['is_opening'] ?? 0),
                    ]);
                }

                // Repopulate Free Promotional Stock Inventory
                if ($freeQty > 0) {
                    $existingFreeBatch = Inventory::where('product_id', $product->id)
                        ->where('branch_id', $branchId)
                        ->where('batch_no', $batchNo)
                        ->where('mrp', $mrp)
                        ->where('selling_price', $sellingPrice)
                        ->where('cost_price', 0)
                        ->where('free', 1)
                        ->first();

                    if ($existingFreeBatch) {
                        $existingFreeBatch->increment('qty', $freeQty);
                        $existingFreeBatch->purchase_line_id = $purchaseLine->id;
                        $existingFreeBatch->save();
                    } else {
                        Inventory::create([
                            'product_id' => $product->id,
                            'branch_id' => $branchId,
                            'purchase_bill_id' => $bill->id,
                            'purchase_line_id' => $purchaseLine->id,
                            'batch_barcode' => $batchBarcode,
                            'batch_no' => $batchNo,
                            'mrp' => $mrp,
                            'selling_price' => $sellingPrice,
                            'cost_price' => 0,
                            'qty' => $freeQty,
                            'sold_qty' => 0,
                            'free' => 1,
                            'rate' => 0,
                            'amount' => 0,
                            'expiry_date' => $lineData['expiry_date'] ?? null,
                            'is_opening' => (int) ($lineData['is_opening'] ?? 0),
                        ]);
                    }
                }

                // ITC Record keeping
                ItcEntry::create([
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $purchaseLine->id,
                    'product_id' => $product->id,
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'total_itc' => round($cgst + $sgst + $igst, 2),
                    'created_by' => $user->id,
                ]);

                $totals['taxable'] += $taxable;
                $totals['cgst'] += $cgst;
                $totals['sgst'] += $sgst;
                $totals['igst'] += $igst;
            }

            $totalPaid = 0;

            if (! empty($validated['payments'])) {
                foreach ($validated['payments'] as $payment) {

                    $amount = (float) $payment['amount'];

                    PurchasePayment::create([
                        'purchase_bill_id' => $bill->id,
                        'amount' => $amount,
                        'method' => $payment['method'],
                        'reference' => $payment['reference'] ?? null,
                        'payment_date' => $payment['payment_date'] ?? now(),
                    ]);

                    $totalPaid += $amount;
                }
            }

            $totalTax = $totals['cgst'] + $totals['sgst'] + $totals['igst'];
            $calculatedGrandTotal = round($totals['taxable'] + $totalTax, 2);
            $dueAmount = max(0, $calculatedGrandTotal - $totalPaid);

            // Final Bill Summary Update
            $bill->update([
                'taxable_value' => $totals['taxable'],
                'cgst_amount' => $totals['cgst'],
                'sgst_amount' => $totals['sgst'],
                'igst_amount' => $totals['igst'],
                'total_tax' => $totalTax,
                'total_amount' => $calculatedGrandTotal,
                'received' => $dueAmount == 0, // fully paid or not
            ]);

            DB::commit();

            return response()->json(['status' => true, 'message' => 'Success', 'bill' => $bill], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, int $id)
    {
        $isPharmacy = $this->isPharmacyStore(Auth::user()->store_id);

        $request->validate([
            'supplier_id' => 'required|integer',
            'bill_no' => 'sometimes|string',
            'bill_date' => 'required|date',
            'tax_type' => 'required|in:inclusive,exclusive',
            'settlement_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'is_lost' => 'sometimes|in:0,1',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.free_qty' => 'nullable|numeric|min:0',
            'lines.*.purchase_rate' => 'required|numeric|min:0',
            'lines.*.mrp' => 'required|numeric|min:0',
            'lines.*.selling_price' => 'required|numeric|min:0',
            'lines.*.gst_rate_id' => 'required|integer',
            'lines.*.batch_no' => $isPharmacy ? 'required|string|max:50' : 'nullable|string',
            'lines.*.expiry_date' => $isPharmacy ? 'required|date' : 'nullable|date',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percent,fixed',
            'lines.*.hsn_code' => 'nullable|string',
            'lines.*.is_opening' => 'sometimes|in:0,1',
            'payments' => 'sometimes|nullable|array',
            'payments.*.id' => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('purchase_payments', 'id')
                    ->where(fn ($query) => $query->where('purchase_bill_id', $id)),
            ],
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.method' => 'required_with:payments|string|in:cash,online,bank',
            'payments.*.reference' => 'nullable|string|max:255',
            'payments.*.payment_date' => 'nullable|date',
        ], $this->lineMessages());

        DB::beginTransaction();

        try {
            $bill = PurchaseBill::with('lines.inventory')->findOrFail($id);
            $branchId = $bill->branch_id;
            $user = Auth::user();
            $supplier = Supplier::where('store_id', $user->store_id)->find($request->supplier_id);
            $branch = Branch::where('store_id', $user->store_id)->find($branchId);

            if (! $supplier || ! $branch) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Invalid supplier or branch for this store.',
                ], 422);
            }
            $originState = $user->role === 'admin'
                ? Store::findOrFail($user->store_id)->state
                : $branch->state;
            $isIntra = $originState === $supplier->state;

            if ($user->role === 'admin' && $bill->store_id != $user->store_id) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized - Admin can only update purchase bills of their own store',
                ], 403);
            }

            if ($user->role === 'manager') {
                if ($bill->store_id != $user->store_id) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - Manager can update only their store purchase bills',
                    ], 403);
                }

                if (! $user->branches()->whereKey($branchId)->exists()) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - Manager can update purchase bills of their assigned branch only',
                    ], 403);
                }
            }

            if (! in_array($user->role, ['admin', 'manager'], true)) {
                DB::rollBack();

                return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
            }

            $oldInventory = Inventory::where('purchase_bill_id', $bill->id)
                ->get();

            $oldInventoryIds = $oldInventory->pluck('id')->toArray();

            PurchaseLine::where('purchase_bill_id', $bill->id)->delete();

            ItcEntry::where('purchase_bill_id', $bill->id)->delete();

            $bill->update([
                'supplier_id' => $request->supplier_id,
                'bill_no' => $request->bill_no ?? $bill->bill_no,
                'bill_date' => $request->bill_date,
                'tax_type' => $request->tax_type,
                'settlement_amount' => $request->settlement_amount,
                'notes' => $request->notes,
                'is_lost' => $request->is_lost ?? 0,
                'updated_by' => $user->id,
            ]);

            $totalTaxable = $totalCgst = $totalSgst = $totalIgst = 0;
            $processedProducts = [];

            foreach ($request->lines as $line) {
                $product = Product::where('store_id', $user->store_id)->find($line['product_id']);

                if (! $product) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid product for this store.',
                    ], 422);
                }

                $qty = (float) $line['qty'];
                $freeQty = (float) ($line['free_qty'] ?? 0);
                $enteredRate = (float) $line['purchase_rate']; // The rate printed on invoice paper
                $mrp = (float) $line['mrp'];
                $sellingPrice = (float) $line['selling_price'];
                $gstRateId = $line['gst_rate_id'];

                if ($isPharmacy && $sellingPrice > $mrp) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => "Selling price MRP se zyada nahi ho sakta: {$product->name}",
                    ], 422);
                }

                $gstRate = optional(GstRate::where('store_id', $user->store_id)->find($gstRateId))->rate ?? 0;
                $discountType = $line['discount_type'] ?? null;
                $discount = (float) ($line['discount'] ?? 0);

                // Calculate Base Value, Deductibles & Taxes based on Billing Mode
                if ($request->tax_type === 'inclusive') {
                    // De-escalate tax rate component back to clean item net-rate
                    $cleanRateWithoutTax = $enteredRate / (1 + ($gstRate / 100));

                    $gross = round($qty * $cleanRateWithoutTax, 2);
                    $discountAmount = $discountType === 'percent'
                        ? round($gross * $discount / 100, 2)
                        : round($discount, 2);

                    $taxable = round(max(0, $gross - $discountAmount), 2);
                    $totalTaxValue = round(($taxable * $gstRate) / 100, 2);

                    $inventoryCostRate = round($cleanRateWithoutTax, 4);
                } else {
                    // Traditional exclusive transaction calculation pipeline
                    $gross = $qty * $enteredRate;
                    $discountAmount = $discountType === 'percent'
                        ? round($gross * $discount / 100, 2)
                        : round($discount, 2);

                    $taxable = round(max(0, $gross - $discountAmount), 2);
                    $totalTaxValue = round(($taxable * $gstRate) / 100, 2);

                    $inventoryCostRate = $enteredRate;
                }

                if ($isIntra) {
                    $cgst = round($totalTaxValue / 2, 2);
                    $sgst = round($totalTaxValue / 2, 2);
                    $igst = 0;
                } else {
                    $cgst = 0;
                    $sgst = 0;
                    $igst = $totalTaxValue;
                }

                $totalGst = $cgst + $sgst + $igst;

                // Manage batches
                $rawBatch = isset($line['batch_no']) ? trim($line['batch_no']) : '';
                if ($isPharmacy) {
                    $rawBatch = strtoupper($rawBatch);
                }
                $hasBatch = $rawBatch !== '';
                $batchNo = $hasBatch ? $rawBatch : $this->generateBatchNo($product->id);

                // Recreate Purchase Line entry
                $purchaseLine = PurchaseLine::create([
                    'purchase_bill_id' => $bill->id,
                    'product_id' => $product->id,
                    'gst_rate_id' => $gstRateId,
                    'hsn_code' => $line['hsn_code'] ?? $product->hsn_code,
                    'taxable_value' => $taxable,
                    'qty' => $qty,
                    'free_qty' => $freeQty,
                    'purchase_rate' => $enteredRate, // Keep original input reference
                    'mrp' => $mrp,
                    'selling_price' => $sellingPrice,
                    'discount_type' => $discountType,
                    'discount' => $discount,
                    'amount' => $taxable,
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'total_gst' => $totalGst,
                    'batch_no' => $batchNo,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'is_opening' => (int) ($line['is_opening'] ?? 0),
                ]);

                // Setup or link Barcodes safely
                $batchBarcode = $product->barcode;
                if (Inventory::where('batch_barcode', $batchBarcode)->exists()) {
                    $batchBarcode = $product->barcode.'-'.strtoupper(Str::random(4));
                }

                $existingInventory = Inventory::where('purchase_bill_id', $bill->id)
                    ->where('product_id', $product->id)
                    ->where('branch_id', $branchId)
                    ->where('batch_no', $batchNo)
                    ->where('free', 0)
                    ->whereIn('id', $oldInventoryIds)
                    ->orderBy('id')
                    ->first();

                if ($existingInventory) {

                    $minimumQty = (float) $existingInventory->sold_qty
                        + (float) ($existingInventory->expired_qty ?? 0);

                    if ($qty < $minimumQty) {
                        throw new \Exception(
                            "Purchase quantity cannot be less than sold/expired quantity for {$product->name}"
                        );
                    }

                    // Update same inventory row with edited purchase details
                    $existingInventory->qty = $qty;
                    $existingInventory->purchase_line_id = $purchaseLine->id;
                    $existingInventory->mrp = $mrp;
                    $existingInventory->selling_price = $sellingPrice;
                    $existingInventory->cost_price = $inventoryCostRate;
                    $existingInventory->rate = $inventoryCostRate;
                    $existingInventory->amount = $qty * $inventoryCostRate;
                    $existingInventory->expiry_date = $line['expiry_date'] ?? null;
                    $existingInventory->is_opening = (int) ($line['is_opening'] ?? 0);
                    $existingInventory->save();

                } else {
                    $batchBarcode = $product->barcode;

                    if (Inventory::where('batch_barcode', $batchBarcode)->exists()) {
                        $batchBarcode = $product->barcode.'-'.strtoupper(Str::random(4));
                    }
                    $expiryDate = $line['expiry_date'] ?? null;

                    Inventory::create([
                        'product_id' => $product->id,
                        'branch_id' => $branchId,
                        'purchase_bill_id' => $bill->id,
                        'purchase_line_id' => $purchaseLine->id,
                        'batch_barcode' => $batchBarcode,
                        'batch_no' => $batchNo,
                        'mrp' => $mrp,
                        'selling_price' => $sellingPrice,
                        'cost_price' => $inventoryCostRate,
                        'qty' => $qty,
                        'sold_qty' => 0,
                        'expired_qty' => 0,
                        'free' => 0,
                        'rate' => $inventoryCostRate,
                        'amount' => $qty * $inventoryCostRate,
                        'expiry_date' => $expiryDate,
                        'is_opening' => (int) ($line['is_opening'] ?? 0),
                    ]);
                }

                if ($freeQty > 0) {
                    $existingFreeInventory = Inventory::where('purchase_bill_id', $bill->id)
                        ->where('product_id', $product->id)
                        ->where('branch_id', $branchId)
                        ->where('batch_no', $batchNo)
                        ->where('free', 1)
                        ->whereIn('id', $oldInventoryIds)
                        ->first();

                    if ($existingFreeInventory) {

                        $minimumQty = (float) $existingFreeInventory->sold_qty
                            + (float) ($existingFreeInventory->expired_qty ?? 0);

                        if ($freeQty < $minimumQty) {
                            throw new \Exception(
                                "Free stock cannot be less than sold/expired quantity for {$product->name}"
                            );
                        }

                        $existingFreeInventory->qty = $freeQty;
                        $existingFreeInventory->purchase_line_id = $purchaseLine->id;
                        $existingFreeInventory->mrp = $mrp;
                        $existingFreeInventory->selling_price = $sellingPrice;
                        $existingFreeInventory->expiry_date = $line['expiry_date'] ?? null;
                        $existingFreeInventory->save();
                    } else {
                        Inventory::create([
                            'product_id' => $product->id,
                            'branch_id' => $branchId,
                            'purchase_bill_id' => $bill->id,
                            'purchase_line_id' => $purchaseLine->id,
                            'batch_barcode' => $batchBarcode,
                            'batch_no' => $batchNo,
                            'mrp' => $mrp,
                            'selling_price' => $sellingPrice,
                            'cost_price' => 0,
                            'qty' => $freeQty,
                            'sold_qty' => 0,
                            'free' => 1,
                            'rate' => 0,
                            'amount' => 0,
                            'expiry_date' => $line['expiry_date'] ?? null,
                            'is_opening' => (int) ($line['is_opening'] ?? 0),
                        ]);
                    }
                }

                // Document modern ITC Records
                ItcEntry::create([
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $purchaseLine->id,
                    'product_id' => $product->id,
                    'gst_rate_id' => $gstRateId,
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'total_itc' => $totalGst,
                    'created_by' => $user->id,
                ]);

                $totalTaxable += $taxable;
                $totalCgst += $cgst;
                $totalSgst += $sgst;
                $totalIgst += $igst;

                $processedProducts[] = $product->id;
            }

            if ($request->has('payments')) {
                PurchasePayment::where('purchase_bill_id', $bill->id)
                    ->where('status', 'success')
                    ->update([
                        'status' => 'reversed',
                        'updated_by' => $user->id,
                        'remarks' => 'Reversed during purchase bill payment update',
                    ]);

                foreach ($request->payments ?? [] as $payment) {
                    $amount = round((float) $payment['amount'], 2);

                    PurchasePayment::create([
                        'purchase_bill_id' => $bill->id,
                        'amount' => $amount,
                        'method' => $payment['method'],
                        'reference' => $payment['reference'] ?? null,
                        'payment_date' => $payment['payment_date'] ?? now(),
                        'status' => 'success',
                        'updated_by' => $user->id,
                        'remarks' => 'Created during purchase bill payment update',
                    ]);
                }
            }

            $totalPaid = (float) PurchasePayment::where('purchase_bill_id', $bill->id)
                ->where('status', 'success')
                ->sum('amount');

            $totalTax = $totalCgst + $totalSgst + $totalIgst;
            $calculatedGrandTotal = round($totalTaxable + $totalTax, 2);
            $dueAmount = max(0, round($calculatedGrandTotal - $totalPaid, 2));

            if ($totalPaid > $calculatedGrandTotal) {
                throw new \Exception('Payment exceeds bill amount');
            }

            $bill->update([
                'taxable_value' => $totalTaxable,
                'cgst_amount' => $totalCgst,
                'sgst_amount' => $totalSgst,
                'igst_amount' => $totalIgst,
                'total_tax' => $totalTax,
                'total_amount' => $calculatedGrandTotal,
                'received' => $dueAmount == 0,
            ]);

            foreach (array_unique($processedProducts) as $pid) {
                $product = Product::find($pid);

                $totalQty = Inventory::where('product_id', $pid)->sum('qty');
                $totalValue = Inventory::where('product_id', $pid)
                    ->where('free', 0)
                    ->sum(DB::raw('qty * rate'));

                $paidQty = Inventory::where('product_id', $pid)->where('free', 0)->sum('qty');

                $product->stock = $totalQty;
                $product->cost_price = $paidQty > 0
                    ? round($totalValue / $paidQty, 2)
                    : $product->cost_price;
                $product->save();
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Purchase bill updated successfully',
                'data' => $bill->load(['lines.product']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error updating purchase bill',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function destroy(int $id)
    {
        DB::beginTransaction();

        try {
            $bill = PurchaseBill::with('lines')->findOrFail($id);
            $user = Auth::user();

            $processedProducts = $bill->lines->pluck('product_id')->unique()->toArray();

            $bill->update([
                'deleted_by' => $user->id,
            ]);

            $bill->delete();

            foreach ($processedProducts as $pid) {
                $product = Product::find($pid);
                if ($product) {
                    $totalQty = Inventory::where('product_id', $pid)->sum('qty');

                    $totalValue = Inventory::where('product_id', $pid)
                        ->where('free', 0)
                        ->sum(DB::raw('qty * rate'));

                    $paidQty = Inventory::where('product_id', $pid)
                        ->where('free', 0)
                        ->sum('qty');

                    $product->stock = $totalQty;
                    $product->cost_price = $paidQty > 0
                        ? round($totalValue / $paidQty, 2)
                        : $product->cost_price;

                    $product->save();
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Purchase bill and all nested items soft-deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error processing soft deletion sequence',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function supplierRanking(Request $request)
    {
        $query = PurchaseBill::query()
            ->select(
                'supplier_id',
                DB::raw('SUM(total_amount) as total_amount'),
                DB::raw('SUM(total_tax) as total_tax'),
                DB::raw('COUNT(*) as bill_count')
            )
            ->groupBy('supplier_id');

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('bill_date', [$request->date_from, $request->date_to]);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        $rows = $query->get();
        $grandTotal = $rows->sum('total_amount');

        $suppliers = Supplier::whereIn('id', $rows->pluck('supplier_id'))
            ->pluck('name', 'id');

        $result = $rows->map(function ($row) use ($suppliers, $grandTotal) {
            return [
                'supplier_id' => $row->supplier_id,
                'supplier_name' => $suppliers[$row->supplier_id] ?? 'Unknown Supplier',
                'total_amount' => (float) $row->total_amount,
                'total_tax' => (float) $row->total_tax,
                'bill_count' => (int) $row->bill_count,
                'share_pct' => $grandTotal > 0
                    ? round(($row->total_amount / $grandTotal) * 100, 1)
                    : 0,
            ];
        })->sortByDesc('total_amount')->values();

        return response()->json([
            'suppliers' => $result,
            'grand_total' => $grandTotal,
        ]);
    }

    public function supplierDetail(Request $request, $supplier)
    {
        $year = $request->get('year', now()->year);

        $query = PurchaseBill::query()
            ->where('supplier_id', $supplier)
            ->whereYear('bill_date', $year);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        $bills = $query
            ->withSum(['payments as paid_sum' => fn ($q) => $q->where('status', 'success')], 'amount')
            ->orderByDesc('bill_date')
            ->get([
                'id', 'bill_no', 'bill_date', 'taxable_value', 'total_tax', 'total_amount', 'received',
            ])
            ->each(function ($b) {
                $total = round((float) $b->total_amount, 2);
                $paid = round((float) ($b->paid_sum ?? 0), 2);

                $b->setAppends([]);
                $b->setAttribute('paid_amount', $paid);
                $b->setAttribute('due_amount', max(0, round($total - $paid, 2)));
                $b->setAttribute('payment_status', $paid <= 0 ? 'due' : ($paid < $total ? 'partial' : 'paid'));
            });

        $monthly = $bills
            ->groupBy(fn ($b) => (int) date('n', strtotime($b->bill_date)))
            ->map(fn ($group) => $group->sum('total_amount'));

        $monthlyData = collect(range(1, 12))->map(fn ($m) => [
            'month' => $m,
            'total' => (float) ($monthly[$m] ?? 0),
        ]);

        return response()->json([
            'supplier_name' => Supplier::find($supplier)?->name,
            'year' => (int) $year,
            'yearly_total' => (float) $bills->sum('total_amount'),
            'monthly' => $monthlyData,
            'bills' => $bills,
        ]);
    }
}
