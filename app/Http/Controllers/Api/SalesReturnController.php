<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\PrescriptionRegister;
use App\Models\SalesBill;
use App\Models\SalesBillLine;
use App\Models\SalesReturn;
use App\Services\FeatureService;
use App\Support\StockLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesReturnController extends Controller
{
    private const NON_RETURNABLE_SCHEDULES = ['H1', 'X'];

    public function index()
    {
        $user = Auth::user();

        try {
            $query = SalesReturn::with([
                'store', 'branch', 'salesBill', 'customer', 'returnLines.product:id,name',
            ])
                ->where('store_id', $user->store_id)
                ->orderBy('id', 'desc');

            if ($user->role === 'cashier') {
                $query->where('processed_by', $user->id);
            } elseif ($user->role === 'manager') {
                $branchIds = $this->allowedBranchIds($user);

                if (empty($branchIds)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'No branch assigned to this manager.',
                    ], 400);
                }

                $query->whereIn('branch_id', $branchIds);
            } elseif ($user->role === 'admin' && request()->filled('branch_id')) {
                $query->where('branch_id', (int) request('branch_id'));
            }

            return response()->json([
                'status' => true,
                'message' => 'Sales return bills fetched successfully',
                'data' => $query->get(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function lookupBill(Request $request)
    {
        $request->validate([
            'bill_no' => 'required|string',
        ]);

        try {
            $user = Auth::user();

            $query = SalesBill::where('bill_no', $request->bill_no)
                ->where('store_id', $user->store_id)
                ->with(['lines.product']);

            if ($user->role !== 'admin') {
                $query->whereIn('branch_id', $this->allowedBranchIds($user));
            }

            $bill = $query->first();

            if (! $bill) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invoice matching this bill number was not found.',
                ], 404);
            }

            $isPharmacy = collect(FeatureService::storeFeatures($user->store_id))->contains('pharmacy');

            $rxByLine = $isPharmacy
                ? PrescriptionRegister::where('sales_bill_id', $bill->id)->get()->keyBy('sales_bill_line_id')
                : collect();

            $batchById = $isPharmacy
                ? DB::table('inventories')
                    ->whereIn('id', $bill->lines->pluck('inventory_id')->filter()->all())
                    ->get(['id', 'batch_no', 'expiry_date'])
                    ->keyBy('id')
                : collect();

            foreach ($bill->lines as $line) {
                $line->total_returned_qty = DB::table('sales_return_lines')
                    ->where('sales_bill_line_id', $line->id)
                    ->sum('qty') ?: 0;

                $factor = max(1, (int) ($line->units_per_pack ?? 1));
                $packType = $line->product->pack_type ?? null;
                $looseUnit = $line->product->loose_unit ?? null;

                $line->is_loose = $factor > 1;
                $line->loose_unit = $looseUnit ?: 'unit';
                $line->pack_type = $packType ?: 'pack';
                $line->sold_label = StockLabel::format($line->qty, $factor, $packType, $looseUnit);
                $line->returned_label = StockLabel::format($line->total_returned_qty, $factor, $packType, $looseUnit);

                if ($isPharmacy) {
                    $rx = $rxByLine->get($line->id);
                    $schedule = $rx->drug_schedule ?? $line->product->drug_schedule ?? 'none';
                    $batch = $batchById->get($line->inventory_id);

                    $line->drug_schedule = $schedule;
                    $line->return_blocked = in_array($schedule, self::NON_RETURNABLE_SCHEDULES, true);
                    $line->batch_no = $batch->batch_no ?? null;
                    $line->expiry_date = ($batch && $batch->expiry_date)
                        ? substr((string) $batch->expiry_date, 0, 10)
                        : null;
                }
            }

            return response()->json([
                'status' => true,
                'data' => $bill,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error retrieving bill records: '.$e->getMessage(),
            ], 500);
        }
    }

    public function processReturn(Request $request)
    {
        $user = Auth::user();

        if (! in_array($user->role, ['manager', 'admin'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized: Only managers or system administrators can process sales returns.',
            ], 403);
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            return response()->json(['error' => 'Missing Idempotency_key_return'], 400);
        }

        $existingReturn = SalesReturn::where('store_id', $user->store_id)
            ->where('last_idempotency_key_return', $idempotencyKey)
            ->first();

        if ($existingReturn) {
            return response()->json([
                'success' => true,
                'message' => 'Duplicate Return Request Ignored',
                'data' => $existingReturn->load('returnLines'),
            ], 200);
        }

        $request->validate([
            'sales_bill_id' => [
                'required',
                'integer',
                Rule::exists('sales_bills', 'id')->where('store_id', $user->store_id),
            ],
            'refund_type' => 'required|in:cash,online,store_credit,credit_note',
            'notes' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1',
            'lines.*.sales_bill_line_id' => 'required|integer|exists:sales_bill_lines,id',
            'lines.*.qty' => 'required|numeric|min:0.01',
            'lines.*.is_damaged' => 'required|boolean',
        ]);

        $billCheck = SalesBill::where('id', $request->sales_bill_id)
            ->where('store_id', $user->store_id)
            ->first();

        if (! $billCheck) {
            return response()->json([
                'status' => false,
                'message' => 'Sales bill not found.',
            ], 404);
        }

        if ($user->role === 'manager'
            && ! in_array((int) $billCheck->branch_id, $this->allowedBranchIds($user), true)) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to process returns for another branch.',
            ], 403);
        }

        try {
            DB::beginTransaction();

            $originalBill = SalesBill::where('id', $billCheck->id)
                ->where('store_id', $user->store_id)
                ->lockForUpdate()
                ->firstOrFail();

            $branchId = (int) $originalBill->branch_id;

            $isPharmacy = collect(FeatureService::storeFeatures($user->store_id))->contains('pharmacy');

            $storeId = str_pad($user->store_id, 2, '0', STR_PAD_LEFT);
            $brId = str_pad($branchId, 2, '0', STR_PAD_LEFT);
            $counterId = str_pad($user->id, 2, '0', STR_PAD_LEFT);
            $date = now()->format('ymd');

            $todayReturnCount = SalesReturn::where('store_id', $user->store_id)
                ->where('branch_id', $branchId)
                ->whereDate('created_at', today())
                ->count();

            $seq = str_pad($todayReturnCount + 1, 4, '0', STR_PAD_LEFT);
            $returnNo = "SR{$storeId}{$brId}{$counterId}{$date}{$seq}";

            $returnSubtotal = 0;
            $returnTotalGst = 0;
            $returnTotalCogs = 0;
            $processedLinesData = [];

            foreach ($request->lines as $lineData) {
                $billLine = SalesBillLine::where('id', $lineData['sales_bill_line_id'])
                    ->where('sales_bill_id', $originalBill->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $alreadyReturnedQty = DB::table('sales_return_lines')
                    ->where('sales_bill_line_id', $billLine->id)
                    ->sum('qty');

                $maxAllowableReturn = $billLine->qty - $alreadyReturnedQty;
                $requiredReturnQty = (float) $lineData['qty'];

                if ($requiredReturnQty > $maxAllowableReturn) {
                    throw new \Exception("Cannot return more items than remaining sale balance for Line item ID: {$billLine->id}. Max allowed: {$maxAllowableReturn}");
                }

                if ((int) $billLine->units_per_pack > 1
                    && abs($requiredReturnQty - round($requiredReturnQty)) > 0.0001) {
                    throw new \DomainException('Loose-selling medicines can only be returned in whole units (for example 3 tablets).');
                }

                $ratio = $requiredReturnQty / (float) $billLine->qty;

                $lineTaxable = round($billLine->taxable_amount * $ratio, 2);
                $lineGst = round($billLine->total_gst * $ratio, 2);
                $lineCgst = round($billLine->cgst * $ratio, 2);
                $lineSgst = round($billLine->sgst * $ratio, 2);
                $lineIgst = round($billLine->igst * $ratio, 2);
                $lineAmount = round($billLine->amount * $ratio, 2);
                $lineCogsRecovered = round($billLine->cogs * $ratio, 2);

                // Last piece of a line: refund the exact remainder so partial returns never exceed the bill line.
                if ($alreadyReturnedQty > 0
                    && abs($alreadyReturnedQty + $requiredReturnQty - (float) $billLine->qty) < 0.0001) {
                    $prev = DB::table('sales_return_lines')
                        ->where('sales_bill_line_id', $billLine->id)
                        ->selectRaw('COALESCE(SUM(taxable_amount),0) AS t, COALESCE(SUM(total_gst),0) AS g,
                            COALESCE(SUM(cgst),0) AS c, COALESCE(SUM(sgst),0) AS s, COALESCE(SUM(igst),0) AS i,
                            COALESCE(SUM(amount),0) AS a, COALESCE(SUM(cogs_recovered),0) AS cg')
                        ->first();

                    $lineTaxable = round($billLine->taxable_amount - $prev->t, 2);
                    $lineGst = round($billLine->total_gst - $prev->g, 2);
                    $lineCgst = round($billLine->cgst - $prev->c, 2);
                    $lineSgst = round($billLine->sgst - $prev->s, 2);
                    $lineIgst = round($billLine->igst - $prev->i, 2);
                    $lineAmount = round($billLine->amount - $prev->a, 2);
                    $lineCogsRecovered = round($billLine->cogs - $prev->cg, 2);
                }

                // Pharmacy: schedule policy + prescription register
                $rxRow = null;
                if ($isPharmacy) {
                    $rxRow = PrescriptionRegister::where('sales_bill_line_id', $billLine->id)
                        ->lockForUpdate()
                        ->first();

                    $prod = DB::table('products')
                        ->where('id', $billLine->product_id)
                        ->first(['name', 'drug_schedule']);

                    $schedule = $rxRow->drug_schedule ?? $prod->drug_schedule ?? 'none';

                    if (in_array($schedule, self::NON_RETURNABLE_SCHEDULES, true)) {
                        throw new \DomainException(
                            ($prod->name ?? 'This medicine')." is Schedule {$schedule} and cannot be returned."
                        );
                    }
                }

                if (! $lineData['is_damaged']) {
                    $inventoryBatch = Inventory::where('id', $billLine->inventory_id)
                        ->where('branch_id', $branchId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $inventoryBatch->decrement('sold_qty', $requiredReturnQty);
                }

                if ($rxRow) {
                    $rxRow->update([
                        'returned_qty' => (float) $rxRow->returned_qty + $requiredReturnQty,
                        'last_returned_at' => now(),
                    ]);
                }

                $processedLinesData[] = [
                    'sales_bill_line_id' => $billLine->id,
                    'product_id' => $billLine->product_id,
                    'inventory_id' => $billLine->inventory_id,
                    'qty' => $requiredReturnQty,
                    'rate' => $billLine->rate,
                    'taxable_amount' => $lineTaxable,
                    'amount' => $lineAmount,
                    'cgst' => $lineCgst,
                    'sgst' => $lineSgst,
                    'igst' => $lineIgst,
                    'total_gst' => $lineGst,
                    'cogs_recovered' => $lineCogsRecovered,
                    'is_damaged' => $lineData['is_damaged'] ? 1 : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $returnSubtotal += $lineAmount;
                $returnTotalGst += $lineGst;
                $returnTotalCogs += $lineCogsRecovered;
            }

            $salesReturn = SalesReturn::create([
                'store_id' => $originalBill->store_id,
                'branch_id' => $branchId,
                'sales_bill_id' => $originalBill->id,
                'return_no' => $returnNo,
                'customer_id' => $originalBill->customer_id,
                'subtotal' => $returnSubtotal,
                'total_gst' => $returnTotalGst,
                'total_refund_amount' => $returnSubtotal,
                'total_cogs_recovered' => $returnTotalCogs,
                'refund_type' => $request->refund_type,
                'processed_by' => $user->id,
                'last_idempotency_key_return' => $idempotencyKey,
                'notes' => $request->notes,
            ]);

            foreach ($processedLinesData as $line) {
                $line['sales_return_id'] = $salesReturn->id;
                DB::table('sales_return_lines')->insert($line);

                if ($line['total_gst'] > 0) {
                    $productGstRateId = DB::table('products')
                        ->where('id', $line['product_id'])
                        ->value('gst_rate_id');

                    DB::table('gst_output_ledgers')->insert([
                        'sales_bill_id' => $originalBill->id,
                        'sales_bill_line_id' => $line['sales_bill_line_id'],
                        'product_id' => $line['product_id'],
                        'gst_rate_id' => $productGstRateId,
                        'cgst' => -$line['cgst'],
                        'sgst' => -$line['sgst'],
                        'igst' => -$line['igst'],
                        'total_gst' => -$line['total_gst'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($originalBill->payment_type === 'credit') {
                $originalBill->decrement('due_amount', $returnSubtotal);
            } else {
                $originalBill->decrement('total_amount', $returnSubtotal);

                $netRevenueReturned = $returnSubtotal - $returnTotalGst;
                $profitReversal = $netRevenueReturned - $returnTotalCogs;
                $originalBill->decrement('total_profit', $profitReversal);
                $originalBill->decrement('total_cogs', $returnTotalCogs);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Sales return processed successfully by manager authorization.',
                'data' => $salesReturn->load('returnLines'),
            ]);

        } catch (\DomainException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Transaction aborted: '.$e->getMessage(),
            ], 500);
        }
    }

    private function allowedBranchIds($user): array
    {
        if ($user->role === 'admin') {
            return Branch::where('store_id', $user->store_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $user->branches()->pluck('branches.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
