<?php

namespace App\Http\Controllers\Api;

use App\Exports\SalesReportExport;
use App\Http\Controllers\Controller;
use App\Services\SalesReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class AiSalesReportDownloadController extends Controller
{
    public function download(
        Request $request,
        SalesReportService $salesReportService
    ) {
        $validated = $request->validate([
            'format' => ['required', 'in:pdf,excel'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:date_from',
            ],
            'branch_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = $request->user();

        if (!in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'message' => 'You are not authorized to download sales reports.',
            ], 403);
        }

        $branchId = $this->resolveAuthorizedBranch(
            $user,
            $validated['branch_id'] ?? null
        );

        $filters = $salesReportService->resolveFilters([
            'date_range' => 'custom',
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'],
            'bill_status' => 'all',
            'store_id' => $user->store_id,
            'branch_id' => $branchId,
        ]);

        $kpis = $salesReportService->getKpis($filters);
        $invoiceData = $salesReportService->getInvoiceTable($filters);
        $rows = $invoiceData['rows'];

        $from = Carbon::parse($validated['date_from'])->toDateString();
        $to = Carbon::parse($validated['date_to'])->toDateString();

        $filename = "sales-report-{$from}-to-{$to}";

        if ($validated['format'] === 'pdf') {
            $pdf = Pdf::loadView('reports.sales-pdf', [
                'from' => $from,
                'to' => $to,
                'kpis' => $kpis,
                'rows' => $rows,
            ])->setPaper('a4', 'landscape');

            return $pdf->download($filename . '.pdf');
        }

        return Excel::download(
            new SalesReportExport($rows),
            $filename . '.xlsx'
        );
    }

    private function resolveAuthorizedBranch($user, ?int $requestedBranchId): ?int
    {
        if ($user->role === 'manager') {
            $branchId = !empty($user->branch_id)
                ? (int) $user->branch_id
                : DB::table('branch_staff')
                    ->where('user_id', $user->id)
                    ->value('branch_id');

            if (!$branchId) {
                abort(403, 'Manager ki assigned branch resolve nahi hui.');
            }

            $this->assertBranchBelongsToStore($user, (int) $branchId);

            return (int) $branchId;
        }

        // Admin: null or 0 means all branches in their own store.
        if (!$requestedBranchId || $requestedBranchId === 0) {
            return null;
        }

        $this->assertBranchBelongsToStore($user, $requestedBranchId);

        return $requestedBranchId;
    }

    private function assertBranchBelongsToStore($user, int $branchId): void
    {
        $exists = DB::table('branches')
            ->where('id', $branchId)
            ->where('store_id', $user->store_id)
            ->exists();

        if (!$exists) {
            abort(403, 'Invalid branch access.');
        }
    }
}
