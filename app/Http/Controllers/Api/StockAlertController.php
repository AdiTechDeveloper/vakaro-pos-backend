<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Support\StockLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StockAlertController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        $branchIds = $user->branches()->pluck('branches.id');

        $query = Inventory::query()
            ->selectRaw('
                product_id,
                branch_id,
                batch_no,
                SUM(qty) as total_qty,
                SUM(sold_qty) as total_sold,
                SUM(COALESCE(expired_qty, 0)) as total_expired,
                MAX(COALESCE(units_per_pack, 1)) as pack_factor
            ')
            ->with([
                'product:id,name,sku,pack_type,loose_unit',
                'branch:id,name',
            ])
            ->groupBy('product_id', 'branch_id', 'batch_no');

        // Admin → all branches of his store
        if ($user->role === 'admin') {
            $query->whereHas('branch', function ($q) use ($user) {
                $q->where('store_id', $user->store_id);
            });

            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }
        }

        // Manager → only assigned branches
        if ($user->role === 'manager') {
            $query->whereIn('branch_id', $branchIds);
        }

        $query->havingRaw('(SUM(qty) - SUM(sold_qty) - SUM(COALESCE(expired_qty, 0))) <= 10 * MAX(COALESCE(units_per_pack, 1))');

        $alerts = $query->get()->map(function ($item) {
            $availableQty = ($item->total_qty ?? 0) - ($item->total_sold ?? 0) - ($item->total_expired ?? 0);

            return [
                'product_id' => $item->product_id,
                'batch_no' => $item->batch_no,
                'product_name' => $item->product->name ?? '-',
                'sku' => $item->product->sku ?? '-',
                'branch_name' => $item->branch->name ?? '-',
                'available_qty' => $availableQty,
                'available_label' => StockLabel::format($availableQty, (int) ($item->pack_factor ?? 1), $item->product->pack_type ?? null, $item->product->loose_unit ?? null),
                'severity' => $availableQty <= 0 ? 'out of stock' : 'warning',
            ];
        });

        return response()->json([
            'total' => $alerts->count(),
            'alerts' => $alerts,
        ]);
    }
}
