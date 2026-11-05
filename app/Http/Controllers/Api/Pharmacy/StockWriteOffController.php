<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Inventory;
use App\Support\StockLabel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockWriteOffController extends Controller
{
    /**
     * POST /expired-stock/write-off
     * body: { items: [{ inventory_id, qty? }] }
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        if (! in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'items' => 'required|array|min:1|max:300',
            'items.*.inventory_id' => 'required|integer',
            'items.*.qty' => 'nullable|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);

        $allowed = $this->allowedBranchIds($user);
        $today = Carbon::today()->toDateString();

        try {
            $summary = DB::transaction(function () use ($request, $user, $allowed, $today) {
                $batches = 0;
                $qtyTotal = 0.0;
                $lossTotal = 0.0;

                foreach ($request->items as $item) {
                    $inv = Inventory::where('id', $item['inventory_id'])
                        ->lockForUpdate()
                        ->first();

                    if (! $inv || ! in_array((int) $inv->branch_id, $allowed, true)) {
                        throw new \DomainException('Invalid stock batch selected.');
                    }

                    $expiry = $inv->expiry_date ? substr((string) $inv->expiry_date, 0, 10) : null;

                    // Only expired stock can be written off (a batch expiring today can still be sold)
                    if (! $expiry || $expiry >= $today) {
                        throw new \DomainException("Only expired stock can be written off (batch {$inv->batch_no}).");
                    }

                    $remaining = (float) $inv->qty
                        - (float) $inv->sold_qty
                        - (float) ($inv->expired_qty ?? 0);

                    if ($remaining <= 0) {
                        throw new \DomainException("Nothing left to write off for batch {$inv->batch_no}.");
                    }

                    $qty = (isset($item['qty']) && $item['qty'] !== '')
                        ? (float) $item['qty']
                        : $remaining;

                    if ((int) $inv->units_per_pack > 1 && abs($qty - round($qty)) > 0.0001) {
                        throw new \DomainException("Loose-selling stock can only be written off in whole units (batch {$inv->batch_no}).");
                    }

                    if ($qty > $remaining + 0.0001) {
                        throw new \DomainException(
                            "Cannot write off more than the remaining stock ({$remaining}) for batch {$inv->batch_no}."
                        );
                    }

                    // expired_qty can be NULL, so increment() is not used
                    $inv->expired_qty = (float) ($inv->expired_qty ?? 0) + $qty;
                    $inv->save();

                    $cost = (float) $inv->cost_price;
                    $loss = round($qty * $cost, 2);

                    DB::table('stock_writeoffs')->insert([
                        'store_id' => $user->store_id,
                        'branch_id' => $inv->branch_id,
                        'product_id' => $inv->product_id,
                        'inventory_id' => $inv->id,
                        'batch_no' => $inv->batch_no,
                        'expiry_date' => $expiry,
                        'qty' => $qty,
                        'cost_price' => $cost,
                        'loss_amount' => $loss,
                        'reason' => 'expired',
                        'notes' => $request->input('notes'),
                        'written_off_by' => $user->id,
                        'written_off_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $batches++;
                    $qtyTotal += $qty;
                    $lossTotal += $loss;
                }

                return [
                    'batches' => $batches,
                    'qty' => round($qtyTotal, 3),
                    'loss' => round($lossTotal, 2),
                ];
            });

            return response()->json([
                'status' => true,
                'message' => "{$summary['batches']} batch(es) written off. Loss: ₹".number_format($summary['loss'], 2),
                'summary' => $summary,
            ]);
        } catch (\DomainException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => false, 'message' => 'Could not write off stock.'], 500);
        }
    }

    /**
     * Write-off history (destruction record)
     * GET /expired-stock/write-offs?date_range=&date_from=&date_to=&branch_id=
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (! in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json(['status' => false, 'message' => 'Unauthorized', 'data' => [], 'total_loss' => 0], 403);
        }

        $request->validate([
            'date_range' => 'nullable|in:all,today,yesterday,last_7_days,this_month,custom',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'branch_id' => 'nullable|integer',
        ]);

        $branchIds = $this->allowedBranchIds($user);

        if ($request->filled('branch_id')) {
            $requested = (int) $request->branch_id;

            if (! in_array($requested, $branchIds, true)) {
                return response()->json(['status' => false, 'message' => 'Invalid branch access.', 'data' => [], 'total_loss' => 0], 403);
            }

            $branchIds = [$requested];
        }

        [$from, $to] = $this->range($request);

        $query = DB::table('stock_writeoffs as w')
            ->join('products as p', 'p.id', '=', 'w.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'w.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'w.written_off_by')
            ->leftJoin('inventories as i', 'i.id', '=', 'w.inventory_id')
            ->where('w.store_id', $user->store_id)
            ->whereIn('w.branch_id', $branchIds ?: [0]);

        if ($from && $to) {
            $query->whereBetween('w.written_off_at', [$from, $to]);
        }

        $rows = $query
            ->orderByDesc('w.written_off_at')
            ->orderByDesc('w.id')
            ->limit(2000)
            ->get([
                'w.id', 'w.written_off_at', 'w.batch_no', 'w.expiry_date', 'w.qty',
                'w.cost_price', 'w.loss_amount', 'w.notes',
                'p.name as product_name', 'b.name as branch_name', 'u.name as written_off_by_name',
                'p.pack_type', 'p.loose_unit', 'i.units_per_pack',
            ]);

        $data = $rows->map(fn ($r) => [
            'id' => $r->id,
            'written_off_at' => Carbon::parse($r->written_off_at)->format('d-m-Y H:i'),
            'branch_name' => $r->branch_name,
            'product_name' => $r->product_name,
            'batch_no' => $r->batch_no,
            'expiry_date' => $r->expiry_date ? Carbon::parse($r->expiry_date)->format('d-m-Y') : null,
            'qty' => (float) $r->qty,
            'qty_label' => StockLabel::format($r->qty, (int) ($r->units_per_pack ?? 1), $r->pack_type, $r->loose_unit),
            'cost_price' => (float) $r->cost_price,
            'loss_amount' => (float) $r->loss_amount,
            'written_off_by_name' => $r->written_off_by_name,
            'notes' => $r->notes,
        ]);

        return response()->json([
            'status' => true,
            'data' => $data->values(),
            'total_loss' => round((float) $rows->sum('loss_amount'), 2),
        ]);
    }

    private function range(Request $request): array
    {
        switch ($request->input('date_range', 'this_month')) {
            case 'all':
                return [null, null];
            case 'today':
                return [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()];
            case 'yesterday':
                return [Carbon::yesterday()->startOfDay(), Carbon::yesterday()->endOfDay()];
            case 'last_7_days':
                return [Carbon::now()->subDays(6)->startOfDay(), Carbon::now()->endOfDay()];
            case 'custom':
                $from = $request->filled('date_from')
                    ? Carbon::parse($request->date_from)->startOfDay()
                    : Carbon::create(2000, 1, 1)->startOfDay();
                $to = $request->filled('date_to')
                    ? Carbon::parse($request->date_to)->endOfDay()
                    : Carbon::now()->endOfDay();

                return [$from, $to];
            default: // this_month
                return [Carbon::now()->startOfMonth()->startOfDay(), Carbon::now()->endOfDay()];
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
