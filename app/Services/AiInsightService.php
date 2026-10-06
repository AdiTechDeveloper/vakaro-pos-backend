<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\AiInsightUsageLimit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiInsightService
{
    public function buildSummary(int $storeId, string $periodType, int $branchId = 0): array
    {
        if ($periodType === 'daily') {
            $start = Carbon::today();
            $end = Carbon::today()->endOfDay();
        } else {
            $start = Carbon::now()->startOfMonth();
            $end = Carbon::now()->endOfMonth();
        }

        $salesQuery = DB::table('sales_bills')
            ->where('store_id', $storeId)
            ->where('bill_status', '!=', 'cancelled')
            ->whereBetween('created_at', [$start, $end]);

        if ($branchId !== 0) {
            $salesQuery->where('branch_id', $branchId);
        }

        $sales = $salesQuery->selectRaw('
                COALESCE(SUM(total_amount),0) as total_sales,
                COALESCE(SUM(total_profit),0) as total_profit,
                COALESCE(SUM(due_amount),0) as total_due,
                COUNT(*) as bill_count
            ')->first();

        $purchaseQuery = DB::table('purchase_bills')
            ->where('store_id', $storeId)
            ->whereBetween('bill_date', [$start->toDateString(), $end->toDateString()]);

        if ($branchId !== 0) {
            $purchaseQuery->where('branch_id', $branchId);
        }

        $purchase = $purchaseQuery->selectRaw('COALESCE(SUM(total_amount),0) as total_purchase')->first();

        $topProductsQuery = DB::table('sales_bill_lines')
            ->join('sales_bills', 'sales_bills.id', '=', 'sales_bill_lines.sales_bill_id')
            ->join('products', 'products.id', '=', 'sales_bill_lines.product_id')
            ->where('sales_bills.store_id', $storeId)
            ->where('sales_bills.bill_status', '!=', 'cancelled')
            ->whereBetween('sales_bills.created_at', [$start, $end]);

        if ($branchId !== 0) {
            $topProductsQuery->where('sales_bills.branch_id', $branchId);
        }

        $topProducts = $topProductsQuery
            ->selectRaw('products.name as product_name, SUM(sales_bill_lines.qty) as qty_sold')
            ->groupBy('products.name')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->pluck('qty_sold', 'product_name');

        return [
            'period' => $periodType,
            'scope' => $branchId === 0 ? 'combined (all branches)' : 'single branch',
            'total_sales' => (float) $sales->total_sales,
            'total_profit' => (float) $sales->total_profit,
            'total_due' => (float) $sales->total_due,
            'bill_count' => (int) $sales->bill_count,
            'total_purchase' => (float) $purchase->total_purchase,
            'top_products' => $topProducts,
        ];
    }

    public function generateInsight(int $storeId, string $periodType, int $branchId = 0): AiInsight
    {
        $summary = $this->buildSummary($storeId, $periodType, $branchId);

        $prompt = 'You are a retail business advisor AI. Based on the JSON data below, '
        .'respond with ONLY a valid JSON object (no markdown, no code fences, no extra text) '
        ."in exactly this format:\n"
        .'{"status_summary": "2-3 sentence plain-English summary of business performance", '
        .'"highlights": ["short important fact 1", "short important fact 2"], '
        .'"suggestions": ["actionable suggestion 1", "actionable suggestion 2"]}'."\n\n"
        .'Rules: Do not invent any numbers — only use the numbers provided below. '
        .'Keep each highlight and suggestion under 20 words. '
        ."Output ONLY the JSON object, nothing else.\n\n"
        ."DATA:\n".json_encode($summary);

        $aiMessage = $this->callGemini($prompt);

        return AiInsight::updateOrCreate(
            [
                'store_id' => $storeId,
                'branch_id' => $branchId,
                'insight_date' => Carbon::today(),
                'period_type' => $periodType,
            ],
            [
                'summary_json' => json_encode($summary),
                'ai_message' => $aiMessage,
            ]
        );
    }

    public function generateBothInsights(int $storeId, int $branchId = 0): array
    {
        $dailySummary = $this->buildSummary($storeId, 'daily', $branchId);
        $monthlySummary = $this->buildSummary($storeId, 'monthly', $branchId);

        $prompt = 'You are a retail business advisor AI. Below is JSON data with two sections: '
            ."'daily_data' (today's numbers) and 'monthly_data' (this month's numbers) for a retail store. "
            ."Respond with ONLY a valid JSON object (no markdown, no code fences, no extra text) in exactly this format:\n"
            .'{"daily": {"status_summary": "...", "highlights": ["...", "..."], "suggestions": ["...", "..."]}, '
            .'"monthly": {"status_summary": "...", "highlights": ["...", "..."], "suggestions": ["...", "..."]}}'."\n\n"
            .'Rules: Do not invent any numbers — only use the numbers provided below. '
            ."Keep each highlight and suggestion under 20 words. Output ONLY the JSON object.\n\n"
            ."DATA:\n".json_encode(['daily_data' => $dailySummary, 'monthly_data' => $monthlySummary]);

        $raw = $this->callGemini($prompt);
        $parsed = json_decode($raw, true);

        $fallback = $this->fallbackJson('AI insight is not available right now. Please try again later.');

        $dailyJson = isset($parsed['daily']) ? json_encode($parsed['daily']) : $fallback;
        $monthlyJson = isset($parsed['monthly']) ? json_encode($parsed['monthly']) : $fallback;

        $daily = AiInsight::updateOrCreate(
            ['store_id' => $storeId, 'branch_id' => $branchId, 'insight_date' => Carbon::today(), 'period_type' => 'daily'],
            ['summary_json' => json_encode($dailySummary), 'ai_message' => $dailyJson]
        );

        $monthly = AiInsight::updateOrCreate(
            ['store_id' => $storeId, 'branch_id' => $branchId, 'insight_date' => Carbon::today(), 'period_type' => 'monthly'],
            ['summary_json' => json_encode($monthlySummary), 'ai_message' => $monthlyJson]
        );

        return [$daily, $monthly];
    }

    public function canGenerate(int $storeId, int $branchId): array
    {
        $usage = AiInsightUsageLimit::firstOrCreate(
            ['store_id' => $storeId, 'branch_id' => $branchId, 'usage_date' => Carbon::today()->toDateString()],
            ['generate_count' => 0]
        );

        return [
            'allowed' => $usage->generate_count < 2,
            'used' => $usage->generate_count,
            'remaining' => max(0, 2 - $usage->generate_count),
        ];
    }

    public function recordUsage(int $storeId, int $branchId): void
    {
        $usage = AiInsightUsageLimit::firstOrCreate(
            ['store_id' => $storeId, 'branch_id' => $branchId, 'usage_date' => Carbon::today()->toDateString()],
            ['generate_count' => 0]
        );

        $usage->increment('generate_count');
    }

    private function callGemini(string $prompt): string
    {
        $apiKey = config('services.gemini.api_key');
        $model = config('services.gemini.model');
        $maxAttempts = 2;
        $lastStatus = null;
        $lastBody = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::connectTimeout(8)->timeout(15)->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                    [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                    ]
                );

                if ($response->successful()) {
                    $text = $response->json('candidates.0.content.parts.0.text');

                    return $text ? $this->cleanJsonText($text) : $this->fallbackJson('Could not understand the AI response.');
                }

                $lastStatus = $response->status();
                $lastBody = $response->body();

                if ($lastStatus === 503 && $attempt < $maxAttempts) {
                    sleep(1);
                    continue;
                }

                break;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $lastStatus = 'connection_timeout';
                $lastBody = $e->getMessage();

                if ($attempt < $maxAttempts) {
                    sleep(1);

                    continue;
                }
                break;
            } catch (\Throwable $e) {
                $lastStatus = 'exception';
                $lastBody = $e->getMessage();
                break;
            }
        }

        Log::error('Gemini API call failed after retries', [
            'status' => $lastStatus,
            'body' => $lastBody,
        ]);

        return $this->fallbackJson('AI insight is not available right now. Please try again later.');
    }

    private function cleanJsonText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);

        return trim($text);
    }

    private function fallbackJson(string $message): string
    {
        return json_encode([
            'status_summary' => $message,
            'highlights' => [],
            'suggestions' => [],
        ]);
    }
}
