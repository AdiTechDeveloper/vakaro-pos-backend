<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\ItcEntry;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Supplier;
use App\Services\FeatureService;
use App\Support\StockLabel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseReturnController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();
            // Build base query
            $query = PurchaseReturn::with([
                'purchaseBill:id,bill_no',
                'supplier:id,name',
                'branch:id,name',
                'lines.product:id,name,sku',
                'lines.gstRate:id,rate',
                'lines' => function ($q) {
                    $q->select(
                        'id',
                        'purchase_return_id',
                        'product_id',
                        'gst_rate_id',
                        'hsn_code',
                        'qty',
                        'free',
                        'rate',
                        'taxable_value',
                        'cgst_amount',
                        'sgst_amount',
                        'igst_amount',
                        'line_total'
                    );
                },
            ]);

            // Restrict manager to allowed branches
            if ($user->role === 'manager') {
                $managerBranchIds = $user->branches()->pluck('branches.id');
                $query->whereIn('branch_id', $managerBranchIds);
            }

            // Restrict admin to own store branches
            if ($user->role === 'admin') {
                $query->whereHas('branch', function ($q) use ($user) {
                    $q->where('store_id', $user->store_id);
                });
            }

            $returns = $query
                ->orderBy('id', 'DESC')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $returns,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = Auth::user();

            // Load purchase return with relations
            $purchaseReturn = PurchaseReturn::with([
                'purchaseBill:id,bill_no',
                'branch:id,name,store_id',
                'supplier:id,name',
                'lines.product:id,name,sku',
                'lines.gstRate:id,rate',
            ])->find($id);

            if (! $purchaseReturn) {
                return response()->json([
                    'status' => false,
                    'message' => 'Purchase return not found',
                ], 404);
            }

            // Store id must be taken from the branch relation
            $returnStoreId = $purchaseReturn->branch->store_id;

            // ----------------------------------------------
            // ADMIN VALIDATION
            // ----------------------------------------------
            if ($user->role === 'admin') {
                if ($returnStoreId != $user->store_id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized. Admin can only access purchase returns of their own store.',
                    ], 403);
                }

                return response()->json([
                    'status' => true,
                    'data' => $purchaseReturn,
                ]);
            }

            // ----------------------------------------------
            // MANAGER VALIDATION
            // ----------------------------------------------
            if ($user->role === 'manager') {

                // Check store
                if ($returnStoreId != $user->store_id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized. Manager can only access purchase returns of their own store.',
                    ], 403);
                }

                // Check branch
                $managerBranchIds = $user->branches()->pluck('branches.id');

                if (! $managerBranchIds->contains($purchaseReturn->branch_id)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized. Manager can only access returns of assigned branches.',
                    ], 403);
                }

                return response()->json([
                    'status' => true,
                    'data' => $purchaseReturn,
                ]);
            }

            // Any other role not allowed
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function generateBatchNo($productId)
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

    public function purchaseReplacement(Request $request)
    {
        $user = Auth::user();
        $isPharmacy = collect(FeatureService::storeFeatures($user->store_id))->contains('pharmacy');

        $validated = $request->validate([
            'purchase_bill_id' => 'required|integer|exists:purchase_bills,id',
            'return_date' => 'required|date',
            'reason' => 'nullable|in:near_expiry,expired,damaged,wrong_item,other',
            'notes' => 'nullable|string|max:500',

            // The item being sent BACK to the supplier (whole packs)
            'return_line' => 'required|array',
            'return_line.purchase_bill_line_id' => 'required|integer|exists:purchase_lines,id',
            'return_line.qty' => 'required|integer|min:1',

            // The NEW stock received from the supplier (same product, whole packs, prices per pack)
            'new_item' => 'required|array',
            'new_item.qty' => 'required|integer|min:1',
            'new_item.purchase_rate' => 'required|numeric|min:0',
            'new_item.mrp' => 'required|numeric|min:0',
            'new_item.selling_price' => 'required|numeric|min:0|lte:new_item.mrp',
            'new_item.batch_no' => ($isPharmacy ? 'required' : 'nullable').'|string|max:50',
            'new_item.expiry_date' => ($isPharmacy ? 'required' : 'nullable').'|date|after_or_equal:today',
        ], [
            'new_item.selling_price.lte' => 'Selling price cannot be more than MRP.',
        ]);

        DB::beginTransaction();
        try {
            $bill = PurchaseBill::where('store_id', $user->store_id)
                ->lockForUpdate()
                ->find($validated['purchase_bill_id']);

            if (! $bill) {
                DB::rollBack();

                return response()->json(['status' => false, 'message' => 'Purchase bill not found.'], 404);
            }

            if ($user->role === 'manager' && ! $user->branches()->whereKey($bill->branch_id)->exists()) {
                DB::rollBack();

                return response()->json(['status' => false, 'message' => 'Unauthorized - This purchase bill is not in your assigned branch.'], 403);
            }

            $branchId = (int) $bill->branch_id;

            $oldLine = PurchaseLine::with('product')
                ->where('purchase_bill_id', $bill->id)
                ->lockForUpdate()
                ->find($validated['return_line']['purchase_bill_line_id']);

            if (! $oldLine) {
                throw new \DomainException('Purchase line does not belong to this bill.');
            }

            $product = $oldLine->product;
            $name = $product->name ?? 'this product';
            $returnQty = (int) $validated['return_line']['qty'];
            $info = $this->returnableInfo($oldLine, $branchId, true);

            $new = $validated['new_item'];
            $factor = $info['factor'];
            $newPacks = (int) $new['qty'];
            $newUnits = $newPacks * $factor;

            // ---- Limits: purchased quantity and stock still available in this branch ----
            if ($info['max_packs'] < 1) {
                throw new \DomainException(
                    "No stock of {$name} (batch {$oldLine->batch_no}) is available to replace in this bill's branch. "
                    .'Purchased and not yet returned: '.max(0, (int) $oldLine->qty - (int) $info['already_returned'])
                    .' pack(s); stock available: '.floor($info['available_units'] / max(1, $factor)).' pack(s).'
                );
            }

            if ($returnQty > $info['max_packs']) {
                throw new \DomainException(
                    "Cannot replace {$returnQty} pack(s) of {$name} (batch {$oldLine->batch_no}). Maximum now: {$info['max_packs']}."
                );
            }

            // A replacement can never bring in more stock than was returned.
            // Any extra stock must be recorded as a separate purchase.
            if ($newPacks > $returnQty) {
                throw new \DomainException(
                    "New quantity ({$newPacks}) cannot be more than the returned quantity ({$returnQty}). "
                    .'Record any extra stock as a separate purchase.'
                );
            }

            // ---- Value of the goods sent back (for the record only; no money moves in a replacement) ----
            $lineQty = (float) $oldLine->qty;
            $ratio = $lineQty > 0 ? $returnQty / $lineQty : 0;

            $taxable = round($oldLine->taxable_value * $ratio, 2);
            $cgst = round($oldLine->cgst * $ratio, 2);
            $sgst = round($oldLine->sgst * $ratio, 2);
            $igst = round($oldLine->igst * $ratio, 2);
            $lineTotal = round($taxable + $cgst + $sgst + $igst, 2);

            // ---- Stock out: old batch (paid rows first, then free rows) ----
            $unitsLeft = (float) $returnQty * $factor;

            foreach ($info['rows'] as $row) {
                if ($unitsLeft <= 0) {
                    break;
                }

                $available = max(0, (float) $row->qty - (float) $row->sold_qty - (float) ($row->expired_qty ?? 0));
                $take = min($available, $unitsLeft);

                if ($take <= 0) {
                    continue;
                }

                $row->qty = (float) $row->qty - $take;
                $row->amount = round((float) $row->qty * (float) $row->cost_price, 2);
                $row->save();

                $unitsLeft -= $take;
            }

            if ($unitsLeft > 0.0001) {
                throw new \DomainException("Stock of {$name} changed while replacing. Please try again.");
            }

            // ---- Stock in: new batch (prices are per pack on the form, stored per single unit) ----
            $unitCost = round((float) $new['purchase_rate'] / $factor, 6);
            $unitMrp = round((float) $new['mrp'] / $factor, 6);
            $unitSelling = round((float) $new['selling_price'] / $factor, 6);

            $batchNo = ! empty($new['batch_no']) ? $new['batch_no'] : $this->generateBatchNo($oldLine->product_id);

            $inventoryIn = Inventory::create([
                'product_id' => $oldLine->product_id,
                'branch_id' => $branchId,
                'purchase_bill_id' => $bill->id,
                'batch_no' => $batchNo,
                'expiry_date' => $new['expiry_date'] ?? null,
                'qty' => $newUnits,
                'free' => 0,
                'rate' => $unitCost,
                'cost_price' => $unitCost,
                'mrp' => $unitMrp,
                'selling_price' => $unitSelling,
                'amount' => round($newUnits * $unitCost, 2),
                'units_per_pack' => $factor,
                'batch_barcode' => ($product->barcode ?? '').'-'.Str::random(4),
            ]);

            // ---- Record ----
            $purchaseReturn = PurchaseReturn::create([
                'purchase_bill_id' => $bill->id,
                'supplier_id' => $bill->supplier_id,
                'return_type' => 'replacement',
                'reason' => $validated['reason'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'branch_id' => $branchId,
                'return_date' => $validated['return_date'],
                'created_by' => $user->id,
                'total_taxable' => 0,
                'total_gst' => 0,
                'total_amount' => 0, // a replacement does not change what we owe the supplier
            ]);

            (new PurchaseReturnLine)->forceFill([
                'purchase_return_id' => $purchaseReturn->id,
                'purchase_bill_line_id' => $oldLine->id,
                'product_id' => $oldLine->product_id,
                'qty' => $returnQty,
                'units_per_pack' => $factor,
                'replacement_units' => $newUnits,
                'replacement_inventory_id' => $inventoryIn->id,
                'rate' => $oldLine->purchase_rate,
                'gst_rate_id' => $oldLine->gst_rate_id,
                'hsn_code' => $oldLine->hsn_code,
                'taxable_value' => $taxable,
                'cgst_amount' => $cgst,
                'sgst_amount' => $sgst,
                'igst_amount' => $igst,
                'line_total' => $lineTotal,
            ])->save();

            // Value difference between new and old packs (information only)
            $diff = round($newPacks * (float) $new['purchase_rate'] - $returnQty * (float) $oldLine->purchase_rate, 2);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Replacement Successful',
                'branch_id' => $branchId,
                'diff' => $diff,
                'data' => $purchaseReturn,
            ]);

        } catch (\DomainException $e) {
            DB::rollBack();

            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['status' => false, 'message' => 'Could not process the replacement.'], 500);
        }
    }

    /**
     * GET /purchase-return/bill/{id}/lines
     * Lines of a purchase bill with what can still be returned to the supplier.
     */
    public function returnableLines($id)
    {
        $user = Auth::user();

        $bill = PurchaseBill::where('store_id', $user->store_id)->find($id);

        if (! $bill) {
            return response()->json(['status' => false, 'message' => 'Purchase bill not found.'], 404);
        }

        if ($user->role === 'manager' && ! $user->branches()->whereKey($bill->branch_id)->exists()) {
            return response()->json(['status' => false, 'message' => 'Unauthorized - This purchase bill is not in your assigned branch.'], 403);
        }

        $lines = PurchaseLine::with('product:id,name,sku,pack_type,loose_unit')
            ->where('purchase_bill_id', $bill->id)
            ->get();

        $data = $lines->map(function ($line) use ($bill) {
            $info = $this->returnableInfo($line, (int) $bill->branch_id);
            $product = $line->product;
            $first = $info['rows']->first();

            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $product->name ?? 'Deleted product',
                'batch_no' => $line->batch_no,
                'expiry_date' => $line->expiry_date ? substr((string) $line->expiry_date, 0, 10) : null,
                'purchased_qty' => (float) $line->qty,
                'returned_qty' => $info['already_returned'],
                'max_packs' => $info['max_packs'],
                'pack_unit' => $product->pack_type ?: 'pack',
                'loose_unit' => $product->loose_unit ?: 'unit',
                'units_per_pack' => $info['factor'],
                'is_loose' => $info['factor'] > 1,
                'stock_label' => StockLabel::format(
                    $info['available_units'],
                    $info['factor'],
                    $product->pack_type ?? null,
                    $product->loose_unit ?? null
                ),
                'purchase_rate' => (float) $line->purchase_rate,
                // Prices are stored per single unit; the forms work in packs
                'mrp' => $first ? round((float) $first->mrp * $info['factor'], 2) : null,
                'selling_price' => $first ? round((float) $first->selling_price * $info['factor'], 2) : null,
            ];
        })->values();

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function purchaseReturn(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'purchase_bill_id' => 'required|integer|exists:purchase_bills,id',
            'return_date' => 'required|date',
            'reason' => 'nullable|in:near_expiry,expired,damaged,wrong_item,other',
            'notes' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1',
            'lines.*.purchase_bill_line_id' => 'required|integer|distinct|exists:purchase_lines,id',
            // Returns to the supplier are always in whole packs (strips, boxes, bottles)
            'lines.*.qty' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            $bill = PurchaseBill::where('store_id', $user->store_id)
                ->lockForUpdate()
                ->find($validated['purchase_bill_id']);

            if (! $bill) {
                DB::rollBack();

                return response()->json(['status' => false, 'message' => 'Purchase bill not found.'], 404);
            }

            if ($user->role === 'manager' && ! $user->branches()->whereKey($bill->branch_id)->exists()) {
                DB::rollBack();

                return response()->json(['status' => false, 'message' => 'Unauthorized - This purchase bill is not in your assigned branch.'], 403);
            }

            // Branch and supplier always come from the bill, never from the client
            $branchId = (int) $bill->branch_id;

            $purchaseReturn = PurchaseReturn::create([
                'purchase_bill_id' => $bill->id,
                'supplier_id' => $bill->supplier_id,
                'return_type' => 'return',
                'reason' => $validated['reason'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'branch_id' => $branchId,
                'return_date' => $validated['return_date'],
                'created_by' => $user->id,
                'total_taxable' => 0,
                'total_gst' => 0,
                'total_amount' => 0,
            ]);

            $totals = ['taxable' => 0.0, 'gst' => 0.0];

            foreach ($validated['lines'] as $lineData) {
                $purchaseLine = PurchaseLine::with('product:id,name')
                    ->where('purchase_bill_id', $bill->id)
                    ->lockForUpdate()
                    ->find($lineData['purchase_bill_line_id']);

                if (! $purchaseLine) {
                    throw new \DomainException('Purchase line does not belong to this bill.');
                }

                $returnQty = (int) $lineData['qty'];
                $info = $this->returnableInfo($purchaseLine, $branchId, true);
                $name = $purchaseLine->product->name ?? 'this product';

                if ($returnQty > $info['max_packs']) {
                    throw new \DomainException(
                        "Cannot return {$returnQty} pack(s) of {$name} (batch {$purchaseLine->batch_no}). Maximum returnable now: {$info['max_packs']}."
                    );
                }

                // ---- Amounts (pro-rata of the bill line; the last piece takes the exact remainder) ----
                $lineQty = (float) $purchaseLine->qty;
                $ratio = $lineQty > 0 ? $returnQty / $lineQty : 0;

                $isFinal = $info['already_returned'] > 0
                    && ! $info['has_replacement']
                    && abs($info['already_returned'] + $returnQty - $lineQty) < 0.0001;

                $taxable = round($purchaseLine->taxable_value * $ratio, 2);
                $cgst = round($purchaseLine->cgst * $ratio, 2);
                $sgst = round($purchaseLine->sgst * $ratio, 2);
                $igst = round($purchaseLine->igst * $ratio, 2);

                if ($isFinal) {
                    $prev = DB::table('purchase_return_lines')
                        ->where('purchase_bill_line_id', $purchaseLine->id)
                        ->selectRaw('COALESCE(SUM(taxable_value),0) AS t, COALESCE(SUM(cgst_amount),0) AS c,
                            COALESCE(SUM(sgst_amount),0) AS s, COALESCE(SUM(igst_amount),0) AS i')
                        ->first();

                    $taxable = round($purchaseLine->taxable_value - $prev->t, 2);
                    $cgst = round($purchaseLine->cgst - $prev->c, 2);
                    $sgst = round($purchaseLine->sgst - $prev->s, 2);
                    $igst = round($purchaseLine->igst - $prev->i, 2);
                }

                $lineGst = round($cgst + $sgst + $igst, 2);
                $lineTotal = round($taxable + $lineGst, 2);

                (new PurchaseReturnLine)->forceFill([
                    'purchase_return_id' => $purchaseReturn->id,
                    'purchase_bill_line_id' => $purchaseLine->id,
                    'product_id' => $purchaseLine->product_id,
                    'qty' => $returnQty,
                    'units_per_pack' => $info['factor'],
                    'rate' => $purchaseLine->purchase_rate,
                    'gst_rate_id' => $purchaseLine->gst_rate_id,
                    'hsn_code' => $purchaseLine->hsn_code,
                    'taxable_value' => $taxable,
                    'cgst_amount' => $cgst,
                    'sgst_amount' => $sgst,
                    'igst_amount' => $igst,
                    'line_total' => $lineTotal,
                ])->save();

                // ---- Stock: take single units out of this batch (paid rows first, then free rows) ----
                $unitsLeft = (float) $returnQty * $info['factor'];

                foreach ($info['rows'] as $row) {
                    if ($unitsLeft <= 0) {
                        break;
                    }

                    $available = max(0, (float) $row->qty - (float) $row->sold_qty - (float) ($row->expired_qty ?? 0));
                    $take = min($available, $unitsLeft);

                    if ($take <= 0) {
                        continue;
                    }

                    $row->qty = (float) $row->qty - $take;
                    $row->amount = round((float) $row->qty * (float) $row->cost_price, 2);
                    $row->save();

                    $unitsLeft -= $take;
                }

                if ($unitsLeft > 0.0001) {
                    throw new \DomainException("Stock of {$name} changed while returning. Please try again.");
                }

                // ---- ITC reversal (only if ITC was claimed on this line) ----
                $itc = $this->itcReversal($purchaseLine, $ratio, $isFinal);

                if ($itc) {
                    ItcEntry::create([
                        'purchase_bill_id' => $bill->id,
                        'purchase_line_id' => $purchaseLine->id,
                        'product_id' => $purchaseLine->product_id,
                        'cgst' => -$itc['cgst'],
                        'sgst' => -$itc['sgst'],
                        'igst' => -$itc['igst'],
                        'total_itc' => -$itc['total'],
                        'created_by' => $user->id,
                    ]);
                }

                $totals['taxable'] += $taxable;
                $totals['gst'] += $lineGst;
            }

            $purchaseReturn->update([
                'total_taxable' => round($totals['taxable'], 2),
                'total_gst' => round($totals['gst'], 2),
                'total_amount' => round($totals['taxable'] + $totals['gst'], 2),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Return Processed Successfully',
                'branch_id' => $branchId,
                'data' => $purchaseReturn,
            ]);

        } catch (\DomainException $e) {
            DB::rollBack();

            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['status' => false, 'message' => 'Could not process the purchase return.'], 500);
        }
    }

    /** All stock rows of the batch a purchase line created (merged and free rows included). */
    private function batchRows(PurchaseLine $line, int $branchId, bool $lock = false)
    {
        $query = Inventory::where('product_id', $line->product_id)
            ->where('branch_id', $branchId)
            ->where('batch_no', $line->batch_no)
            ->orderBy('free')
            ->orderBy('id');

        if ($line->expiry_date) {
            $query->whereDate('expiry_date', Carbon::parse($line->expiry_date)->toDateString());
        } else {
            $query->whereNull('expiry_date');
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** What can still be returned for a purchase line (in packs). */
    private function returnableInfo(PurchaseLine $line, int $branchId, bool $lock = false): array
    {
        $rows = $this->batchRows($line, $branchId, $lock);

        $factor = $rows->isNotEmpty() ? max(1, (int) $rows->first()->units_per_pack) : 1;

        $availableUnits = (float) $rows->sum(fn ($r) => max(
            0,
            (float) $r->qty - (float) $r->sold_qty - (float) ($r->expired_qty ?? 0)
        ));

        $alreadyReturned = (float) DB::table('purchase_return_lines')
            ->where('purchase_bill_line_id', $line->id)
            ->sum('qty');

        $remainingPurchased = max(0, (float) $line->qty - $alreadyReturned);
        $stockPacks = (int) floor(($availableUnits + 0.0001) / $factor);

        return [
            'rows' => $rows,
            'factor' => $factor,
            'available_units' => $availableUnits,
            'already_returned' => $alreadyReturned,
            'max_packs' => (int) min(floor($remainingPurchased), $stockPacks),
            'has_replacement' => DB::table('purchase_return_lines as l')
                ->join('purchase_returns as r', 'r.id', '=', 'l.purchase_return_id')
                ->where('l.purchase_bill_line_id', $line->id)
                ->where('r.return_type', 'replacement')
                ->exists(),
        ];
    }

    /** ITC to reverse for a (partial) return. Null when no ITC was claimed on the line. */
    private function itcReversal(PurchaseLine $line, float $ratio, bool $isFinal): ?array
    {
        $entries = ItcEntry::where('purchase_line_id', $line->id)->get();
        $claimed = $entries->where('total_itc', '>', 0);

        if ($claimed->isEmpty()) {
            return null;
        }

        $reversed = $entries->where('total_itc', '<', 0);
        $out = [];

        foreach (['cgst', 'sgst', 'igst'] as $key) {
            $original = (float) $claimed->sum($key);
            $remaining = round($original - abs((float) $reversed->sum($key)), 2);

            $out[$key] = $isFinal
                ? max(0, $remaining)
                : max(0, min($remaining, round($original * $ratio, 2)));
        }

        $out['total'] = round($out['cgst'] + $out['sgst'] + $out['igst'], 2);

        return $out['total'] > 0 ? $out : null;
    }
}
