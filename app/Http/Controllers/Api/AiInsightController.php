<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiInsight;
use App\Models\Branch;
use App\Services\AiInsightService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AiInsightController extends Controller
{
    public function branches()
    {
        $user = Auth::user();
        $storeId = $user->store_id;

        if ($user->role === 'admin') {
            $branches = Branch::where('store_id', $storeId)->select('id', 'name')->get();
            $list = $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->toArray();
            array_unshift($list, ['id' => 0, 'name' => 'All Branches (Combined)']);

            return response()->json(['status' => true, 'branches' => $list]);
        }

        if ($user->role === 'manager') {
            $branches = $user->branches()->select('branches.id', 'branches.name')->get();

            return response()->json(['status' => true, 'branches' => $branches]);
        }

        return response()->json(['status' => false, 'message' => 'Not allowed.'], 403);
    }

    public function latest(Request $request, AiInsightService $service)
    {
        $user = Auth::user();

        if (! in_array($user->role, ['admin', 'manager'])) {
            return response()->json(['status' => false, 'message' => 'Not allowed.'], 403);
        }

        $storeId = $user->store_id;
        $branchId = (int) $request->query('branch_id', 0);

        $accessCheck = $this->checkAccess($user, $storeId, $branchId);
        if ($accessCheck !== true) {
            return $accessCheck;
        }

        $daily = AiInsight::where('store_id', $storeId)
            ->where('branch_id', $branchId)
            ->where('period_type', 'daily')
            ->where('insight_date', Carbon::today())
            ->first();

        $monthly = AiInsight::where('store_id', $storeId)
            ->where('branch_id', $branchId)
            ->where('period_type', 'monthly')
            ->where('insight_date', Carbon::today())
            ->first();

        $usage = $service->canGenerate($storeId, $branchId);

        return response()->json([
            'status' => true,
            'daily' => $daily,
            'monthly' => $monthly,
            'remaining_today' => $usage['remaining'],
        ]);
    }

    public function refresh(Request $request, AiInsightService $service)
    {
        set_time_limit(120);

        $user = Auth::user();

        if (! in_array($user->role, ['admin', 'manager'])) {
            return response()->json(['status' => false, 'message' => 'You are not allowed to generate AI insights.'], 403);
        }

        $validated = $request->validate([
            'branch_id' => 'required|integer',
        ]);

        $storeId = $user->store_id;
        $branchId = (int) $validated['branch_id'];

        $accessCheck = $this->checkAccess($user, $storeId, $branchId);
        if ($accessCheck !== true) {
            return $accessCheck;
        }

        $limit = $service->canGenerate($storeId, $branchId);
        if (! $limit['allowed']) {
            return response()->json([
                'status' => false,
                'message' => 'Daily limit reached for this branch (2 reports per day). Please try again tomorrow.',
            ], 429);
        }

        try {
            [$daily, $monthly] = $service->generateBothInsights($storeId, $branchId);
            $service->recordUsage($storeId, $branchId);
        } catch (\Throwable $e) {
            Log::error('AI insight generation failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while generating the report. Please try again.',
            ], 500);
        }

        return response()->json([
            'status' => true,
            'daily' => $daily,
            'monthly' => $monthly,
            'remaining_today' => $limit['remaining'] - 1,
        ]);
    }

    private function checkAccess($user, int $storeId, int $branchId)
    {
        if ($branchId === 0) {
            if ($user->role !== 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only admin can access the combined report for all branches.',
                ], 403);
            }

            return true;
        }

        if ($user->role === 'admin') {
            $allowedBranchIds = Branch::where('store_id', $storeId)->pluck('id')->toArray();
        } else {
            $allowedBranchIds = $user->branches->pluck('id')->toArray();
        }

        if (! in_array($branchId, $allowedBranchIds)) {
            return response()->json(['status' => false, 'message' => 'You do not have access to this branch.'], 403);
        }

        return true;
    }
}
