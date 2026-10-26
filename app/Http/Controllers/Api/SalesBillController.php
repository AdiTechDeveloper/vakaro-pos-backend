<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerLoyaltyTransaction;
use App\Models\CustomerWalletTransaction;
use App\Models\GstOutputLedger;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\SalesBill;
use App\Models\SalesBillLine;
use App\Models\SalesBillPayment;
use App\Models\Store;
use App\Services\WeightBarcodeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Milon\Barcode\Facades\DNS1DFacade as DNS1D;

class SalesBillController extends Controller
{
    private function authorizeBillAccess(SalesBill $bill, $user): void
    {
        abort_unless((int) $bill->store_id === (int) $user->store_id, 404, 'Sales bill not found.');

        if ($user->role !== 'admin') {
            abort_unless(
                in_array((int) $bill->branch_id, $this->allowedCustomerBranchIds($user), true),
                403,
                'You are not allowed to access bills from another branch.'
            );
        }
    }

    public function scanBarcode(Request $request)
    {
        $request->validate([
            'barcode' => 'required',
            'branch_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $storeId = $user->store_id;

        if ($user->role === 'admin') {
            $branchIds = $request->branch_id
                ? [$request->branch_id]
                : Branch::where('store_id', $storeId)->pluck('id')->toArray();
        } elseif ($user->role === 'manager' || $user->role === 'cashier') {
            $branchIds = $user->branches->pluck('id')->toArray();
            if ($request->branch_id && in_array($request->branch_id, $branchIds)) {
                $branchIds = [$request->branch_id];
            }
        } else {
            $branchIds = Branch::where('store_id', $storeId)->pluck('id')->toArray();
        }

        $barcode = trim($request->barcode);
        $today = date('Y-m-d');

        if (WeightBarcodeService::isWeightBarcode($barcode)) {
            $decoded = WeightBarcodeService::decode($barcode);

            if (! $decoded) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid weight barcode (checksum mismatch).',
                ], 404);
            }

            $product = Product::with('gstRate')
                ->where('store_id', $storeId)
                ->where('plu_code', $decoded['plu_code'])
                ->where('sale_type', 'weight')
                ->first();

            if (! $product) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No weight-type product found for this PLU code.',
                ], 404);
            }

            if (! $product->price_per_kg || $product->price_per_kg <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This product has no price per kg configured.',
                ], 404);
            }

            $weightKg = round($decoded['price'] / $product->price_per_kg, 3);

            $batch = Inventory::where('product_id', $product->id)
                ->whereIn('branch_id', $branchIds)
                ->whereRaw('(qty - sold_qty - expired_qty) > 0')
                ->where(function ($q) use ($today) {
                    $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
                })
                ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
                ->first();

            if (! $batch) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Out of stock for this product.',
                ], 404);
            }

            $availableStock = $batch->qty - $batch->sold_qty - $batch->expired_qty;

            if ($weightKg > $availableStock) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Not enough stock for this weight. Available: {$availableStock} kg",
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'is_weight_item' => true,
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'barcode' => $barcode,
                    'plu_code' => $product->plu_code,
                    'sale_type' => $product->sale_type,
                    'gst_rate' => $product->gstRate,
                    'gst_inclusive' => (int) $product->gst_inclusive,
                    'is_price_override' => 0,
                ],
                'inventory_id' => $batch->id,
                'batch_no' => $batch->batch_no,
                'batch_barcode' => $batch->batch_barcode,
                'qty' => $weightKg,
                'selling_price' => $product->price_per_kg,
                'total_amount' => $decoded['price'],
                'mrp' => $batch->mrp,
                'cost_price' => $batch->cost_price,
                'total_stock' => $availableStock,
            ]);
        }

        $product = Product::with('gstRate')
            ->where('store_id', $storeId)
            ->where('barcode', $barcode)
            ->first();

        if (! $product) {
            $batchMatch = Inventory::where('batch_barcode', $barcode)
                ->orWhere('batch_barcode', 'like', $barcode.'-%')
                ->first();

            if ($batchMatch) {
                $product = Product::with('gstRate')->find($batchMatch->product_id);
            }
        }

        if (! $product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found',
            ], 404);
        }

        $batchesRaw = Inventory::where('product_id', $product->id)
            ->whereIn('branch_id', $branchIds)
            ->whereNull('deleted_at')
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->get();

        $batches = $batchesRaw->filter(function ($b) use ($today) {
            $stock = (float) $b->qty - (float) $b->sold_qty - (float) ($b->expired_qty ?? 0);
            $notExpired = ! $b->expiry_date || $b->expiry_date >= $today;

            return $stock > 0 && $notExpired;
        })
            ->groupBy(fn ($b) => $b->batch_no.'|'.number_format((float) $b->selling_price, 2, '.', ''))
            ->map(function ($g) {
                $first = $g->first();

                return [
                    'inventory_id' => $first->id,
                    'batch_no' => $first->batch_no,
                    'batch_barcode' => $first->batch_barcode,
                    'mrp' => $first->mrp,
                    'selling_price' => $first->selling_price,
                    'cost_price' => $first->cost_price,
                    'expiry_date' => $first->expiry_date,
                    'total_stock' => $g->sum(fn ($b) => (float) $b->qty - (float) $b->sold_qty - (float) ($b->expired_qty ?? 0)),
                ];
            })->values();

        if ($batches->isEmpty()) {
            $hasExpiredOnly = $batchesRaw->isNotEmpty();

            return response()->json([
                'status' => 'error',
                'message' => $hasExpiredOnly
                    ? 'All available stock for this product has expired.'
                    : 'Product is out of stock.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'is_weight_item' => false,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'sale_type' => $product->sale_type ?? 'unit',
                'gst_rate' => $product->gstRate,
                'is_price_override' => $product->is_price_override,
            ],
            'batches' => $batches,
        ]);
    }

    public function index(Request $request)
    {
        $user = Auth::user();

        try {
            $query = SalesBill::with(['store', 'branch', 'user', 'lines.product', 'lines', 'payments'])
                ->where('store_id', $user->store_id)
                ->orderBy('id', 'desc');

            if ($user->role === 'cashier') {
                $query->where('user_id', $user->id);
            } elseif ($user->role === 'manager') {
                $branchIds = $user->branches->pluck('id')->toArray();

                if (empty($branchIds)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'No branch assigned to this manager.',
                    ], 400);
                }

                $query->whereIn('branch_id', $branchIds);
            } elseif ($user->role === 'admin' && $request->filled('branch_id')) {
                $query->where('branch_id', (int) $request->query('branch_id'));
            }

            if ($request->filled('search')) {
                $term = trim($request->query('search'));
                $query->where('bill_no', 'like', '%'.$term.'%');
                $query->limit(100);
            } elseif ($request->filled('selected_date') || $request->filled('date')) {
                $query->whereDate('created_at', $request->query('selected_date', $request->query('date')));
            }

            if ($request->has('limit')) {
                $query->limit((int) $request->query('limit'));
            }

            return response()->json([
                'status' => true,
                'message' => 'Sales bills fetched successfully',
                'data' => $query->get(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $user = Auth::user();

        try {
            $bill = SalesBill::with([
                'lines.product',
                'lines',
                'payments',
            ])->find($id);

            $this->authorizeBillAccess($bill, $user);

            if (! $bill) {
                return response()->json([
                    'status' => false,
                    'message' => 'Sales bill not found.',
                ], 404);
            }

            if ($user->role === 'cashier') {
                if ($bill->user_id !== $user->id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'You are not allowed to view this bill.',
                    ], 403);
                }
            } elseif ($user->role === 'manager') {
                $branchIds = $user->branches->pluck('id')->toArray();

                if (! in_array($bill->branch_id, $branchIds)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'You are not allowed to view bills from another branch.',
                    ], 403);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Sales bill fetched successfully',
                'data' => $bill,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $user = Auth::user();

        if (! $idempotencyKey) {
            return response()->json([
                'error' => 'Missing Idempotency Key',
            ], 400);
        }

        // Fast path for retries; the locked check below handles concurrent requests.
        $existing = SalesBill::where(
            'store_id',
            $user->store_id
        )->where(
            'last_idempotency_key_store',
            $idempotencyKey
        )->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Duplicate Store Request Ignored',
                'data' => $existing,
            ], 200);
        }

        $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.inventory_id' => 'required|integer',
            'lines.*.qty' => 'required|numeric|min:0.01',

            'customer_id' => 'nullable|exists:customers,id',
            'customer' => 'required|array',
            'customer.name' => 'nullable|string|max:255',
            'customer.mobile' => 'required|string|max:15',
            'branch_id' => 'nullable|integer',

            'payment_type' => 'required|in:cash,online,split,credit,wallet',
            'cash_received' => 'nullable|numeric|min:0',
            'online_received' => 'nullable|numeric|min:0',
            'balance_return' => 'nullable|numeric|min:0',
            'lines.*.selling_price' => 'nullable|numeric|min:0',
            'lines.*.original_price' => 'nullable|numeric|min:0',
            'lines.*.is_price_overridden' => 'nullable|boolean',
            'selected_date' => 'nullable|date',
            'points_redeemed' => 'nullable|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            // Serialize bill creation per store without adding a sequence table.
            $store = Store::whereKey($user->store_id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = SalesBill::where('store_id', $user->store_id)
                ->where('last_idempotency_key_store', $idempotencyKey)
                ->first();

            if ($existing) {
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Duplicate Store Request Ignored',
                    'data' => $existing,
                ], 200);
            }

            $branchId = $this->resolveCustomerBranchId(
                $request->branch_id,
                $user
            );

            if (! $branchId) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'User has no branch assigned.',
                ], 400);
            }

            $branch = Branch::where('id', $branchId)
                ->where('store_id', $user->store_id)
                ->firstOrFail();

            $billDate = $request->filled('selected_date')
                ? Carbon::parse($request->input('selected_date'))
                    ->setTimeFrom(now())
                : now();

            $datePrefix = $billDate->format('ymd');

            $todayCount = SalesBill::where('store_id', $user->store_id)
                ->where('branch_id', $branchId)
                ->whereDate('created_at', $billDate->toDateString())
                ->count();

            $sequence = str_pad(
                $todayCount + 1,
                4,
                '0',
                STR_PAD_LEFT
            );

            $storePad = str_pad($user->store_id, 2, '0', STR_PAD_LEFT);
            $branchPad = str_pad($branchId, 2, '0', STR_PAD_LEFT);
            $userPad = str_pad($user->id, 2, '0', STR_PAD_LEFT);

            $billNo = $storePad.$branchPad.$userPad
                .$datePrefix.$sequence;

            // Create Sales Bill Record
            $bill = SalesBill::create([
                'store_id' => $user->store_id,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'bill_no' => $billNo,
                'bill_status' => 'pending',
                'payment_status' => 'unpaid',
                'created_by' => $user->id,
                'last_idempotency_key_store' => $idempotencyKey,
                'customer_id' => $request->customer_id,
                'payment_type' => $request->payment_type,
                'created_at' => $billDate,
            ]);

            $subtotal = 0;
            $totalGst = 0;
            $totalSaved = 0;
            $totalCogs = 0;
            $totalProfit = 0;
            $processedProducts = [];

            foreach ($request->lines as $lineData) {
                $product = Product::with('gstRate')
                    ->findOrFail($lineData['product_id']);

                // Validate the selected inventory belongs to
                // the requested product and current branch.
                $selectedInventory = Inventory::where(
                    'id',
                    $lineData['inventory_id']
                )
                    ->where('product_id', $product->id)
                    ->where('branch_id', $branchId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $inventoryPrice = (float) $selectedInventory->selling_price;

                $isOverridden = ! empty($lineData['is_price_overridden']) && $lineData['is_price_overridden'] == true;

                $price = $isOverridden
                    && isset($lineData['selling_price'])
                    ? (float) $lineData['selling_price']
                    : $inventoryPrice;

                $originalPrice = (float) (
                    $lineData['original_price'] ?? $inventoryPrice
                );

                $overridePrice = $isOverridden ? $price : null;
                $mrp = (float) $selectedInventory->mrp;
                $requiredQty = (float) $lineData['qty'];

                if ($price <= 0) {
                    throw new \Exception(
                        "Invalid selling price for {$product->name}."
                    );
                }

                $batchNo = $selectedInventory->batch_no;
                $selectedSellingPrice = $selectedInventory->selling_price;

                $batchRows = Inventory::where('product_id', $product->id)
                    ->where('branch_id', $branchId)
                    ->where('batch_no', $batchNo)
                    ->where('selling_price', $selectedSellingPrice)
                    ->whereNull('deleted_at')
                    ->whereRaw(
                        '(qty - sold_qty - COALESCE(expired_qty, 0)) > 0'
                    )
                    ->where(function ($q) use ($billDate) {
                        $q->whereNull('expiry_date')
                            ->orWhereDate(
                                'expiry_date',
                                '>=',
                                $billDate->toDateString()
                            );
                    })
                    // FEFO: earliest expiry first
                    ->orderByRaw(
                        'CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END'
                    )
                    ->orderBy('expiry_date', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                $availableStock = $batchRows->sum(function ($inv) {
                    return max(
                        0,
                        (float) $inv->qty
                            - (float) $inv->sold_qty
                            - (float) ($inv->expired_qty ?? 0)
                    );
                });

                if ($availableStock < $requiredQty) {
                    throw new \Exception(
                        "Insufficient stock at selling price ₹{$selectedSellingPrice} "
                            ."for batch {$batchNo}. "
                            ."Available: {$availableStock}, "
                            ."Requested: {$requiredQty}."
                    );
                }

                // COGS and stock allocation across inventory rows
                $remaining = $requiredQty;
                $totalLineCogs = 0;
                $inventoryAllocations = [];

                foreach ($batchRows as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $available = max(
                        0,
                        (float) $batch->qty
                            - (float) $batch->sold_qty
                            - (float) ($batch->expired_qty ?? 0)
                    );

                    if ($available <= 0) {
                        continue;
                    }

                    $consume = min($available, $remaining);

                    $purchaseRate = (float) $batch->cost_price;
                    $gstRate = (float) ($product->gstRate->rate ?? 0);

                    if ($batch->purchase_gst_inclusive && $gstRate > 0) {
                        $purchaseRate = $purchaseRate * 100
                            / (100 + $gstRate);
                    }

                    // Preserve the exact stock allocation for auditing.
                    $inventoryAllocations[] = [
                        'inventory_id' => $batch->id,
                        'qty' => $consume,
                        'cost_price' => $purchaseRate,
                    ];

                    $batch->sold_qty = (float) $batch->sold_qty + $consume;
                    $batch->save();

                    $totalLineCogs += $purchaseRate * $consume;
                    $remaining -= $consume;
                }

                // Defensive check: never create a partially allocated sale.
                if ($remaining > 0.000001) {
                    throw new \Exception(
                        'Unable to allocate the full quantity for '
                            ."{$product->name}."
                    );
                }

                // Tax Logic - retained from your existing method
                $gstRate = (float) ($product->gstRate->rate ?? 0);

                if ($gstRate > 0) {
                    if ($product->gst_inclusive) {
                        $taxable = ($price * 100 / (100 + $gstRate))
                            * $requiredQty;

                        $totalLineGst = ($price * $requiredQty) - $taxable;
                    } else {
                        $taxable = $price * $requiredQty;
                        $totalLineGst = ($taxable * $gstRate) / 100;
                    }
                } else {
                    $taxable = $price * $requiredQty;
                    $totalLineGst = 0;
                }

                $taxable = round($taxable, 2);
                $totalLineGst = round($totalLineGst, 2);

                // Split tax using the branch that owns this sale.
                $storeState = $store->state ?? '';
                $branchState = $branch->state ?? '';

                $cgst = $sgst = $igst = 0;

                if ($gstRate > 0) {
                    if ($storeState == $branchState) {
                        $cgst = round($totalLineGst / 2, 2);
                        $sgst = $totalLineGst - $cgst;
                    } else {
                        $igst = $totalLineGst;
                    }
                }

                $lineAmount = round(
                    $product->gst_inclusive
                        ? ($price * $requiredQty)
                        : ($taxable + $totalLineGst),
                    2
                );
                $totalLineCogs = round($totalLineCogs, 2);

                $remainingTaxable = $taxable;
                $remainingAmount = $lineAmount;
                $remainingCgst = $cgst;
                $remainingSgst = $sgst;
                $remainingIgst = $igst;
                $remainingGst = $totalLineGst;
                $remainingCogs = $totalLineCogs;

                foreach ($inventoryAllocations as $allocationIndex => $allocation) {
                    $isLastAllocation = $allocationIndex
                        === count($inventoryAllocations) - 1;
                    $allocationRatio = $allocation['qty'] / $requiredQty;

                    $allocationTaxable = $isLastAllocation
                        ? $remainingTaxable
                        : min(
                            $remainingTaxable,
                            round($taxable * $allocationRatio, 2)
                        );
                    $allocationAmount = $isLastAllocation
                        ? $remainingAmount
                        : min(
                            $remainingAmount,
                            round($lineAmount * $allocationRatio, 2)
                        );
                    $allocationCgst = $isLastAllocation
                        ? $remainingCgst
                        : min(
                            $remainingCgst,
                            round($cgst * $allocationRatio, 2)
                        );
                    $allocationSgst = $isLastAllocation
                        ? $remainingSgst
                        : min(
                            $remainingSgst,
                            round($sgst * $allocationRatio, 2)
                        );
                    $allocationIgst = $isLastAllocation
                        ? $remainingIgst
                        : min(
                            $remainingIgst,
                            round($igst * $allocationRatio, 2)
                        );
                    $allocationGst = $isLastAllocation
                        ? $remainingGst
                        : min(
                            $remainingGst,
                            round($totalLineGst * $allocationRatio, 2)
                        );
                    $allocationCogs = $isLastAllocation
                        ? $remainingCogs
                        : min(
                            $remainingCogs,
                            round(
                                $allocation['cost_price'] * $allocation['qty'],
                                2
                            )
                        );

                    $salesLine = SalesBillLine::create([
                        'sales_bill_id' => $bill->id,
                        'product_id' => $product->id,
                        'branch_id' => $branchId,
                        'inventory_id' => $allocation['inventory_id'],
                        'qty' => $allocation['qty'],
                        'rate' => $price,
                        'selling_price' => $price,
                        'original_price' => $originalPrice,
                        'override_price' => $overridePrice,
                        'is_price_overridden' => $isOverridden ? 1 : 0,
                        'taxable_amount' => $allocationTaxable,
                        'amount' => $allocationAmount,
                        'cgst' => $allocationCgst,
                        'sgst' => $allocationSgst,
                        'igst' => $allocationIgst,
                        'total_gst' => $allocationGst,
                        'cogs' => $allocationCogs,
                        'profit' => $allocationTaxable - $allocationCogs,
                        'created_at' => $billDate,
                    ]);

                    if ($isOverridden) {
                        \App\Models\PriceOverride::create([
                            'sale_bill_id' => $bill->id,
                            'sale_bill_line_id' => $salesLine->id,
                            'product_id' => $product->id,
                            'branch_id' => $branchId,
                            'original_price' => $originalPrice,
                            'override_price' => $price,
                            'difference' => round(
                                $originalPrice - $price,
                                2
                            ),
                            'qty' => $allocation['qty'],
                            'total_loss' => round(
                                ($originalPrice - $price) * $allocation['qty'],
                                2
                            ),
                            'overridden_by' => $user->id,
                            'created_at' => $billDate,
                        ]);
                    }

                    if ($allocationGst > 0) {
                        GstOutputLedger::create([
                            'sales_bill_id' => $bill->id,
                            'sales_bill_line_id' => $salesLine->id,
                            'product_id' => $product->id,
                            'gst_rate_id' => $product->gst_rate_id,
                            'cgst' => $allocationCgst,
                            'sgst' => $allocationSgst,
                            'igst' => $allocationIgst,
                            'total_gst' => $allocationGst,
                            'created_at' => $billDate,
                        ]);
                    }

                    $remainingTaxable -= $allocationTaxable;
                    $remainingAmount -= $allocationAmount;
                    $remainingCgst -= $allocationCgst;
                    $remainingSgst -= $allocationSgst;
                    $remainingIgst -= $allocationIgst;
                    $remainingGst -= $allocationGst;
                    $remainingCogs -= $allocationCogs;
                }

                $subtotal += $lineAmount;
                $totalGst += $totalLineGst;
                $totalCogs += $totalLineCogs;
                $totalProfit += ($taxable - $totalLineCogs);
                $totalSaved += ($mrp - $price) * $requiredQty;

                $processedProducts[] = $product->id;
            }

            // Final Bill Update
            $bill->update([
                'subtotal' => $subtotal,
                'total_gst' => $totalGst,
                'total_amount' => $subtotal,
                'total_saved' => $totalSaved,
                'total_cogs' => $totalCogs,
                'total_profit' => $totalProfit,
            ]);

            $customerData = [
                'name' => $request->customer['name'] ?? null,
                'add1' => $request->customer['add1'] ?? null,
                'add2' => $request->customer['add2'] ?? null,
                'area' => $request->customer['area'] ?? null,
                'city' => $request->customer['city'] ?? null,
            ];

            $customer = Customer::firstOrCreate(
                [
                    'store_id' => $user->store_id,
                    'mobile' => $request->customer['mobile'],
                ],
                array_merge($customerData, [
                    'branch_id' => $branchId,
                    'name' => $customerData['name'] ?: 'Walk-in Customer',
                ])
            );

            $customer->update(array_filter(
                $customerData,
                fn ($v) => $v !== null && $v !== ''
            ));

            $bill->customer_id = $customer->id;

            // Loyalty Points — Redemption + Earning
            $customer = Customer::where('id', $customer->id)
                ->lockForUpdate()
                ->first();

            $loyaltyBalance = (float) $customer->loyalty_points;
            $requestedRedeemPoints = (float) $request->input(
                'points_redeemed',
                0
            );

            $pointsRedeemed = 0;
            $pointsDiscountAmount = 0;

            // 1 point = ₹1
            $pointValue = 1;

            if ($requestedRedeemPoints > 0) {
                if ($requestedRedeemPoints > $loyaltyBalance) {
                    throw new \Exception(
                        'Insufficient loyalty points. '
                            ."Available: {$loyaltyBalance}, "
                            ."Requested: {$requestedRedeemPoints}"
                    );
                }

                $maxRedeemableAmount = $subtotal;

                $pointsDiscountAmount = min(
                    $requestedRedeemPoints * $pointValue,
                    $maxRedeemableAmount
                );

                $pointsRedeemed = round(
                    $pointsDiscountAmount / $pointValue,
                    2
                );
            }

            // Payable amount
            $payableAmount = max(
                $subtotal - $pointsDiscountAmount,
                0
            );

            // Earn 1% of net payable amount
            $pointsEarned = round($payableAmount * 0.01, 2);

            $runningBalance = $loyaltyBalance;

            if ($pointsRedeemed > 0) {
                $balanceBefore = $runningBalance;
                $runningBalance -= $pointsRedeemed;

                CustomerLoyaltyTransaction::create([
                    'customer_id' => $customer->id,
                    'sales_bill_id' => $bill->id,
                    'type' => 'redeemed',
                    'points' => $pointsRedeemed,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $runningBalance,
                    'note' => "Redeemed on bill {$bill->bill_no}",
                    'created_by' => $user->id,
                ]);
            }

            if ($pointsEarned > 0) {
                $balanceBefore = $runningBalance;
                $runningBalance += $pointsEarned;

                CustomerLoyaltyTransaction::create([
                    'customer_id' => $customer->id,
                    'sales_bill_id' => $bill->id,
                    'type' => 'earned',
                    'points' => $pointsEarned,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $runningBalance,
                    'note' => "Earned on bill {$bill->bill_no} "
                        .'(1% of net payable amount)',
                    'created_by' => $user->id,
                ]);
            }

            $customer->loyalty_points = $runningBalance;
            $customer->save();

            $bill->points_redeemed = $pointsRedeemed;
            $bill->points_discount_amount = $pointsDiscountAmount;
            $bill->points_earned = $pointsEarned;

            if ($request->payment_type === 'credit') {
                $bill->paid_amount = 0;
                $bill->due_amount = $payableAmount;
                $bill->payment_status = 'unpaid';
            } else {
                $cashReceived = (float) (
                    $request->cash_received ?? 0
                );

                $onlineReceived = (float) (
                    $request->online_received ?? 0
                );

                $paidAmount = min(
                    $cashReceived + $onlineReceived,
                    $payableAmount
                );

                $bill->cash_received = $cashReceived;
                $bill->online_received = $onlineReceived;
                $bill->paid_amount = $paidAmount;
                $bill->due_amount = max(
                    $payableAmount - $paidAmount,
                    0
                );

                if ($bill->due_amount == 0) {
                    $bill->payment_status = 'paid';
                } elseif ($paidAmount > 0) {
                    $bill->payment_status = 'partial';
                } else {
                    $bill->payment_status = 'unpaid';
                }
            }

            $bill->save();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Sales bill created successfully',
                'data' => $bill->load('lines'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Sales bill creation failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => false,
                'message' => $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                    ? 'Product or inventory record was not found.'
                    : $e->getMessage(),
            ], 500);
        }
    }

    public function pay(Request $request)
    {
        $request->validate([
            'sales_bill_id' => 'required|integer',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.transaction_id' => 'nullable|string',
            'payments.*.gateway' => 'nullable|string',
            'payments.*.cash_received' => 'nullable|numeric|min:0',
            'payments.*.balance_return' => 'nullable|numeric|min:0',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return response()->json([
                'status' => false,
                'message' => 'Idempotency-Key header is required.',
            ], 400);
        }

        $bill = SalesBill::findOrFail($request->sales_bill_id);
        $this->authorizeBillAccess($bill, Auth::user());

        // prevent duplicate
        if ($bill->last_idempotency_key_payment === $idempotencyKey) {
            return response()->json([
                'status' => true,
                'message' => 'Duplicate request ignored. Returning previous result.',
                'bill' => $bill->load('payments'),
            ]);
        }

        DB::beginTransaction();

        try {

            $walletAmount = collect($request->payments)->where('method', 'wallet')->sum('amount');

            if ($walletAmount > 0) {
                if (! $bill->customer_id) {
                    throw new \Exception('Wallet payment requires a customer on this bill.');
                }

                $customer = Customer::where('id', $bill->customer_id)->lockForUpdate()->first();

                if (! $customer || $customer->opening_balance < $walletAmount) {
                    throw new \Exception('Insufficient wallet balance. Available: ₹'.($customer->opening_balance ?? 0));
                }

                $before = $customer->opening_balance;
                $customer->opening_balance -= $walletAmount;
                $customer->save();

                CustomerWalletTransaction::create([
                    'customer_id' => $customer->id,
                    'type' => 'debit',
                    'amount' => $walletAmount,
                    'balance_before' => $before,
                    'balance_after' => $customer->opening_balance,
                    'source_type' => 'bill_applied',
                    'source_id' => $bill->id,
                    'created_by' => Auth::id(),
                ]);
            }

            $payableAmount = max((float) $bill->total_amount - (float) $bill->points_discount_amount, 0);

            $totalPaid = 0;
            $cashReceivedSum = 0;
            $onlineReceivedSum = 0;

            foreach ($request->payments as $payment) {

                SalesBillPayment::create([
                    'sales_bill_id' => $bill->id,
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'transaction_id' => $payment['transaction_id'] ?? null,
                    'gateway' => $payment['gateway'] ?? null,
                    'status' => 'success',
                    'created_at' => $bill->created_at,
                ]);

                $totalPaid += $payment['amount'];

                if ($payment['method'] === 'cash') {
                    $cashReceivedSum += (float) ($payment['cash_received'] ?? $payment['amount']);
                } elseif ($payment['method'] === 'online') {
                    $onlineReceivedSum += (float) $payment['amount'];
                }
            }

            $bill->cash_received = (float) $bill->cash_received + $cashReceivedSum;
            $bill->online_received = (float) $bill->online_received + $onlineReceivedSum;

            $bill->paid_amount = min($bill->paid_amount + $totalPaid, $payableAmount);
            $bill->due_amount = max($payableAmount - $bill->paid_amount, 0);

            if ($cashReceivedSum > 0) {
                $cashAppliedToThisBill = min($cashReceivedSum, $payableAmount - ($bill->paid_amount - $totalPaid));
                $bill->balance_return = max($cashReceivedSum - $cashAppliedToThisBill, 0);
            }

            // Payment status
            if (floatval($bill->due_amount) == 0) {
                $bill->payment_status = 'paid';
                $bill->bill_status = 'completed';
            } elseif ($bill->paid_amount > 0) {
                $bill->payment_status = 'partial';
            } else {
                $bill->payment_status = 'unpaid';
            }

            $bill->last_idempotency_key_payment = $idempotencyKey;
            $bill->save();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Payment recorded successfully',
                'bill' => $bill->load('payments'),
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function collectPayment(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'sales_bill_id' => [
                'required',
                Rule::exists('sales_bills', 'id')->where('store_id', $user->store_id),
            ],
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string',
            'transaction_id' => 'nullable|string',
            'gateway' => 'nullable|string',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return response()->json([
                'status' => false,
                'message' => 'Idempotency-Key is required',
            ], 400);
        }

        DB::beginTransaction();

        try {
            $bill = SalesBill::lockForUpdate()->findOrFail($request->sales_bill_id);
            $this->authorizeBillAccess($bill, $user);

            // Prevent duplicate request
            if ($bill->last_idempotency_key_payment === $idempotencyKey) {
                return response()->json([
                    'status' => true,
                    'message' => 'Duplicate request ignored',
                    'bill' => $bill->load('payments'),
                ]);
            }

            // Prevent overpayment
            if ($request->amount > $bill->due_amount) {
                throw new \Exception('Payment exceeds due amount');
            }

            // Save payment
            SalesBillPayment::create([
                'sales_bill_id' => $bill->id,
                'method' => $request->method,
                'amount' => $request->amount,
                'transaction_id' => $request->transaction_id,
                'gateway' => $request->gateway,
                'status' => 'success',
                'payment_phase' => 'collection', // KEY DIFFERENCE
            ]);

            $totalPaid = SalesBillPayment::where('sales_bill_id', $bill->id)
                ->where('status', 'success')
                ->sum('amount');

            $bill->paid_amount = $totalPaid;
            $bill->due_amount = $bill->total_amount - $totalPaid;

            if ($request->method === 'cash') {
                $bill->cash_received = (float) $bill->cash_received + (float) $request->amount;
            } elseif ($request->method === 'online') {
                $bill->online_received = (float) $bill->online_received + (float) $request->amount;
            }

            // Update status
            if ($bill->due_amount == 0) {
                $bill->payment_status = 'paid';
                $bill->bill_status = 'completed';
            } elseif ($totalPaid > 0) {
                $bill->payment_status = 'partial';
            } else {
                $bill->payment_status = 'unpaid';
            }

            $bill->last_idempotency_key_payment = $idempotencyKey;
            $bill->save();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Payment collected successfully',
                'bill' => $bill->load('payments'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function customerWithDue()
    {
        $user = Auth::user();
        $branchIds = $this->allowedCustomerBranchIds($user);

        if ($user->role === 'admin' && request()->filled('branch_id')) {
            abort_unless(in_array((int) request('branch_id'), $branchIds, true), 403, 'Invalid branch');
        }

        $customers = SalesBill::select([
            'customer_id',
            DB::raw('SUM(due_amount) as total_due'),
        ])
            ->where('store_id', $user->store_id)
            ->whereIn('branch_id', $branchIds)
            ->when(
                $user->role === 'admin' && request()->filled('branch_id'),
                fn ($q) => $q->where('branch_id', request('branch_id'))
            )
            ->whereNotNull('customer_id')
            ->where('due_amount', '>', 0)
            ->groupBy('customer_id')
            ->with('customer:id,branch_id,name,mobile')
            ->get();

        if ($user->role === 'admin' && request()->filled('branch_id')) {
            abort_unless(in_array((int) request('branch_id'), $branchIds, true), 403, 'Invalid branch');
        }

        return response()->json([
            'status' => true,
            'data' => $customers,
        ]);
    }

    public function customerDue(string $customerMobile)
    {
        $user = Auth::user();
        $branchIds = $this->allowedCustomerBranchIds($user);
        $customer = Customer::where('store_id', $user->store_id)
            ->where('mobile', $customerMobile)
            ->first();

        if ($user->role === 'admin' && request()->filled('branch_id')) {
            abort_unless(in_array((int) request('branch_id'), $branchIds, true), 403, 'Invalid branch');
        }

        if (! $customer) {
            return response()->json(['customer' => null]);
        }

        $totalDue = SalesBill::where('customer_id', $customer->id)
            ->whereIn('branch_id', $branchIds)
            ->when(
                $user->role === 'admin' && request()->filled('branch_id'),
                fn ($q) => $q->where('branch_id', request('branch_id'))
            )
            ->where('due_amount', '>', 0)
            ->sum('due_amount');

        return response()->json([
            'customer' => $customer,
            'total_due' => $totalDue,
        ]);
    }

    private function allowedCustomerBranchIds($user): array
    {
        if ($user->role === 'admin') {
            return $user->store?->branches()->pluck('id')->all() ?? [];
        }

        return $user->branches()->pluck('branches.id')->all();
    }

    private function resolveCustomerBranchId(?int $branchId, $user): int
    {
        $allowedBranchIds = $this->allowedCustomerBranchIds($user);
        $branchId = $branchId ?? ($user->branches()->first()?->id ?? ($allowedBranchIds[0] ?? null));

        abort_unless($branchId && in_array((int) $branchId, $allowedBranchIds, true), 403, 'Invalid branch');

        return (int) $branchId;
    }

    public function getPrintData(Request $request)
    {
        $user = Auth::user();

        $ids = $request->id; // array [1,2]

        if (! is_array($ids) || empty($ids)) {
            return response()->json([
                'status' => false,
                'message' => 'No bill IDs provided',
            ], 422);
        }

        $ids = array_values(array_filter(array_map('intval', $ids)));

        $query = SalesBill::with([
            'store',
            'branch',
            'user',
            'customer',
            'lines.product',
            'lines.inventory',
            'lines.gstRate',
        ])
            ->where('store_id', $user->store_id)
            ->whereIn('id', $ids);

        if ($user->role !== 'admin') {
            $query->whereIn('branch_id', $this->allowedCustomerBranchIds($user));
        }

        $bills = $query->get();

        if ($bills->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Bills not found',
            ], 404);
        }

        $response = $bills->map(function ($bill) {
            $items = $bill->lines->map(function ($line) {
                $sellingPrice = (float) (
                    $line->selling_price
                    ?? $line->rate
                );

                $mrp = (float) (
                    $line->inventory->mrp
                    ?? $line->product->mrp
                );

                return [
                    'name' => $line->product->name,
                    'qty' => $line->qty,
                    'mrp' => round($mrp, 2),
                    'selling' => round($sellingPrice, 2),
                    'original_price' => round(
                        $line->original_price ?? $sellingPrice,
                        2
                    ),
                    'is_price_overridden' => (bool) $line->is_price_overridden,
                    'amount' => $line->amount,
                    'saved' => round(
                        ($mrp - $sellingPrice) * (float) $line->qty,
                        2
                    ),
                    'cgst' => $line->cgst,
                    'sgst' => $line->sgst,
                    'igst' => $line->igst,
                    'cess' => $line->cess,
                    'gst_total' => $line->total_gst,
                ];
            });

            $barcode = 'data:image/png;base64,'.DNS1D::getBarcodePNG($bill->bill_no, 'C128', 3, 90);

            return [
                'store' => [
                    'name' => $bill->store->name,
                    'state' => $bill->store->state,
                    'phone' => $bill->store->phone,
                ],

                'branch' => [
                    'name' => $bill->branch->name,
                    'address' => $bill->branch->address,
                ],

                'customer' => [
                    'name' => $bill->customer->name ?? $bill->customer_name ?? 'N/A',
                    'mobile' => $bill->customer->mobile ?? $bill->customer->phone ?? $bill->customer_mobile ?? 'N/A',
                ],

                'bill' => [
                    'number' => $bill->bill_no,
                    'date' => $bill->created_at->setTimezone('Asia/Kolkata')->format('d-m-Y H:i'),
                    'cashier' => $bill->user->name,
                    'subtotal' => $bill->subtotal,
                    'total_gst' => $bill->total_gst,
                    'total_amount' => $bill->total_amount,
                    'total_saved' => $bill->total_saved,
                    'cgst_total' => $items->sum('cgst'),
                    'sgst_total' => $items->sum('sgst'),
                    'igst_total' => $items->sum('igst'),
                    'cess_total' => $items->sum('cess'),
                ],

                'items' => $items,
                'barcode' => $barcode,
                'footer' => 'Thank You! Visit Again',
            ];
        });

        return response()->json([
            'status' => true,
            'data' => $response,
        ]);
    }

    public function gstReport()
    {
        try {
            $user = Auth::user();

            $query = GstOutputLedger::with('bill')->whereHas('bill', function ($q) use ($user) {
                $q->where('store_id', $user->store_id);

                if ($user->role !== 'admin') {
                    $q->whereIn('branch_id', $this->allowedCustomerBranchIds($user));
                }
            });

            $branchId = $user->branches->pluck('id')->first();

            if ($user->role === 'manager') {
                $query->whereHas(
                    'bill',
                    fn ($q) => $q->where('branch_id', $branchId)
                );
            }

            if ($user->role === 'admin') {
                $query->whereHas(
                    'bill',
                    fn ($q) => $q->where('store_id', $user->store_id)
                );
            }

            $data = $query->orderBy('id', 'DESC')->get();

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function processBillPayment($bill, $amount, $method, $transaction_id = null, $gateway = null)
    {
        if ($amount <= 0) {
            throw new \Exception('Invalid payment amount');
        }

        if ($amount > $bill->due_amount) {
            throw new \Exception('Payment exceeds due amount for bill ID: '.$bill->id);
        }

        // Insert payment record
        SalesBillPayment::create([
            'sales_bill_id' => $bill->id,
            'method' => $method,
            'amount' => $amount,
            'transaction_id' => $transaction_id,
            'gateway' => $gateway,
            'status' => 'success',
            'payment_phase' => 'collection',
        ]);

        // Update bill
        $bill->paid_amount += $amount;
        $bill->due_amount -= $amount;

        if ($method === 'cash') {
            $bill->cash_received = (float) $bill->cash_received + $amount;
        } elseif ($method === 'online') {
            $bill->online_received = (float) $bill->online_received + $amount;
        }

        if ($bill->due_amount == 0) {
            $bill->payment_status = 'paid';
            $bill->bill_status = 'completed';
        } else {
            $bill->payment_status = 'partial';
        }

        $bill->save();
    }

    public function customerPayDue(Request $request)
    {

        $user = Auth::user();
        $customer = Customer::where('store_id', $user->store_id)->findOrFail($request->customer_id);

        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string',
            'transaction_id' => 'nullable|string',
            'gateway' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $remaining = $request->amount;

            $bills = SalesBill::where('customer_id', $customer->id)
                ->where('store_id', $user->store_id)
                ->whereIn('branch_id', $this->allowedCustomerBranchIds($user))
                ->where('due_amount', '>', 0)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($bills->isEmpty()) {
                throw new \Exception('No pending dues for this customer');
            }

            $paymentBreakdown = [];

            foreach ($bills as $bill) {
                if ($remaining <= 0) {
                    break;
                }

                $payAmount = min($remaining, $bill->due_amount);

                $this->processBillPayment(
                    $bill,
                    $payAmount,
                    $request->method,
                    $request->transaction_id,
                    $request->gateway
                );

                $paymentBreakdown[] = [
                    'bill_id' => $bill->id,
                    'paid' => $payAmount,
                    'remaining_due' => $bill->due_amount,
                ];

                $remaining -= $payAmount;
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Customer due paid successfully',
                'data' => [
                    'total_paid' => $request->amount - $remaining,
                    'remaining_amount' => $remaining,
                    'breakdown' => $paymentBreakdown,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function changePayment(Request $request, $id)
    {
        $user = Auth::user();

        $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string|in:cash,online,wallet',
            'payments.*.amount' => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $bill = SalesBill::lockForUpdate()->findOrFail($id);
            $this->authorizeBillAccess($bill, $user);

            // Restriction: Only same day edit
            if ($bill->created_at->lt(now()->startOfDay())) {
                throw new \Exception('Old bill payment cannot be edited');
            }

            // Reverse old payments
            $oldPayments = SalesBillPayment::where('sales_bill_id', $bill->id)->get();

            foreach ($oldPayments as $payment) {
                $payment->update([
                    'status' => 'reversed',
                    'remarks' => 'Edited',
                    'updated_by' => auth()->id(),
                ]);
            }

            // Reset bill
            $bill->paid_amount = 0;
            $bill->due_amount = $bill->total_amount;
            $bill->cash_received = 0;
            $bill->online_received = 0;

            $totalPaid = 0;
            $cashSum = 0;
            $onlineSum = 0;

            // Apply new payments (supports split)
            foreach ($request->payments as $p) {

                SalesBillPayment::create([
                    'sales_bill_id' => $bill->id,
                    'method' => $p['method'],
                    'amount' => $p['amount'],
                    'status' => 'success',
                    'payment_phase' => 'adjustment',
                    'created_by' => auth()->id(),
                ]);

                $totalPaid += $p['amount'];

                if ($p['method'] === 'cash') {
                    $cashSum += $p['amount'];
                } elseif ($p['method'] === 'online') {
                    $onlineSum += $p['amount'];
                }
            }

            // Prevent overpayment
            if ($totalPaid > $bill->total_amount) {
                throw new \Exception('Payment exceeds bill amount');
            }

            // Update totals
            $bill->paid_amount = $totalPaid;
            $bill->due_amount = $bill->total_amount - $totalPaid;
            $bill->cash_received = $cashSum;
            $bill->online_received = $onlineSum;

            // Status
            if ($bill->due_amount == 0) {
                $bill->payment_status = 'paid';
            } elseif ($totalPaid > 0) {
                $bill->payment_status = 'partial';
            } else {
                $bill->payment_status = 'unpaid';
            }

            $bill->save();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Payment updated successfully',
                'bill' => $bill->load('payments'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
