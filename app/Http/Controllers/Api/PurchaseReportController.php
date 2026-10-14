<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PurchaseReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseReportController extends Controller
{
    public function __construct(protected PurchaseReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date_range' => 'nullable|string|in:today,yesterday,last_7_days,this_month,custom',
            'date_from' => 'nullable|date|required_if:date_range,custom',
            'date_to' => 'nullable|date|required_if:date_range,custom',
            'branch_id' => 'nullable|integer|min:0',
            'supplier_id' => 'nullable|integer',
            'is_lost' => 'nullable|boolean',
        ]);

        $scope = $this->resolveScope($request);

        $filters = $this->service->resolveFilters(array_merge(
            $request->only('date_range', 'date_from', 'date_to', 'supplier_id', 'is_lost'),
            $scope
        ));

        return response()->json([
            'kpis' => $this->service->getKpis($filters),
            'bills' => $this->service->getBillTable($filters),
            'products' => $this->service->getProductPerformance($filters),
            'supplier_breakdown' => $this->service->getSupplierBreakdown($filters),
            'filters_applied' => $filters,
        ]);
    }

    private function resolveScope(Request $request): array
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['admin', 'manager'], true)) {
            abort(403, 'You are not authorized to view purchase reports.');
        }

        $requestedBranchId = (int) $request->input('branch_id', 0);

        if ($user->role === 'admin') {
            if ($requestedBranchId > 0) {
                $belongs = DB::table('branches')
                    ->where('id', $requestedBranchId)
                    ->where('store_id', $user->store_id)
                    ->exists();

                abort_unless($belongs, 403, 'Invalid branch access.');

                return [
                    'store_id' => $user->store_id,
                    'branch_id' => $requestedBranchId,
                ];
            }

            return [
                'store_id' => $user->store_id,
                'branch_id' => null,
            ];
        }

        // Manager: sirf assigned branches
        $allowed = $user->branches()->pluck('branches.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        abort_if(empty($allowed), 403, 'No branch assigned to this manager.');

        if ($requestedBranchId > 0) {
            abort_unless(in_array($requestedBranchId, $allowed, true), 403, 'Invalid branch access.');

            return [
                'store_id' => $user->store_id,
                'branch_id' => $requestedBranchId,
            ];
        }

        return [
            'store_id' => $user->store_id,
            'branch_id' => null,
            'branch_ids' => $allowed,
        ];
    }
}
