<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesReportController extends Controller
{
    public function __construct(protected SalesReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date_range' => 'nullable|string|in:today,yesterday,last_7_days,this_month,custom',
            'date_from' => 'nullable|date|required_if:date_range,custom',
            'date_to' => 'nullable|date|required_if:date_range,custom',
            'branch_id' => 'nullable|integer|min:0',
            'bill_status' => 'nullable|string|in:all,pending,completed,cancelled',
        ]);

        $scope = $this->resolveScope($request);

        $filters = $this->service->resolveFilters(array_merge(
            $request->only('date_range', 'date_from', 'date_to', 'bill_status'),
            $scope
        ));

        return response()->json([
            'kpis' => $this->service->getKpis($filters),
            'invoices' => $this->service->getInvoiceTable($filters),
            'products' => $this->service->getProductPerformance($filters),
            'payment_methods' => $this->service->getPaymentMethods($filters),
            'summary' => $this->service->getPaymentSummary($filters),
            'price_overrides' => $this->service->getPriceOverrides($filters),
            'sales_extremes' => $this->service->getSalesExtremes($filters),
            'filters_applied' => $filters,
        ]);
    }

    public function monthlySales(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
            'branch_id' => 'nullable|integer|min:0',
            'bill_status' => 'nullable|string|in:all,pending,completed,cancelled',
        ]);

        $year = (int) ($request->input('year') ?: now()->year);

        $scope = $this->resolveScope($request);

        $filters = $this->service->resolveFilters(array_merge($scope, [
            'date_range' => 'custom',
            'date_from' => "{$year}-01-01",
            'date_to' => "{$year}-12-31",
            'bill_status' => $request->input('bill_status', 'all'),
        ]));

        return response()->json([
            'year' => $year,
            'monthly_sales' => $this->service->getMonthlySales($filters),
            'filters_applied' => $filters,
        ]);
    }

    private function resolveScope(Request $request): array
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['admin', 'manager'], true)) {
            abort(403, 'You are not authorized to view sales reports.');
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
