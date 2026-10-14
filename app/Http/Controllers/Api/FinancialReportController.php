<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialReportController extends Controller
{
    public function index(Request $request, FinancialReportService $service)
    {
        $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'branch_id' => 'nullable|integer|min:0',
        ]);

        $user = $request->user();

        abort_unless(
            $user && in_array($user->role, ['admin', 'manager'], true),
            403,
            'You are not authorized to view financial reports.'
        );

        $requestedBranchId = (int) $request->input('branch_id', 0);

        if ($user->role === 'admin') {
            $allowed = DB::table('branches')
                ->where('store_id', $user->store_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } else {
            $allowed = $user->branches()
                ->pluck('branches.id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        abort_if(empty($allowed), 403, 'No branch assigned.');

        if ($requestedBranchId > 0) {
            abort_unless(in_array($requestedBranchId, $allowed, true), 403, 'Invalid branch access.');
            $branchIds = [$requestedBranchId];
        } else {
            $branchIds = $allowed;
        }

        $from = $request->filled('from_date')
            ? Carbon::parse($request->from_date)->startOfDay()
            : now()->startOfMonth();

        $to = $request->filled('to_date')
            ? Carbon::parse($request->to_date)->endOfDay()
            : now();

        $data = $service->getFinancialReport([
            'store_id' => $user->store_id,
            'branch_ids' => $branchIds,
            'branch_id' => $requestedBranchId ?: null,
            'from' => $from,
            'to' => $to,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }
}
