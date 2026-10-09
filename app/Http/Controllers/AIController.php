<?php

namespace App\Http\Controllers;

use App\Services\SalesReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AIController extends Controller
{
public function chat(
        Request $request,
        SalesReportService $salesReportService
     ) {

        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $user = Auth::user();

        $branchId = $this->resolveBranchId(
            $user,
            $request->branch_id
        );

        $messages = [
            [
                'role' => 'system',
                'content' => $this->systemPrompt($user, $branchId),
            ],
            [
                'role' => 'user',
                'content' => $request->message,
            ],
        ];

        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_sales_summary',
                    'description' => 'Get actual POS sales data for a date range. Use this whenever the user asks about sales, revenue, business amount, number of bills, collected amount, due amount, profit, or similar sales performance information.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date_range' => [
                                'type' => 'string',
                                'enum' => [
                                    'today',
                                    'yesterday',
                                    'last_7_days',
                                    'this_month',
                                ],
                                'description' => 'The period for which sales data is required.',
                            ],
                            'branch_id' => [
                                'type' => 'integer',
                                'description' => 'Optional branch ID. Use this only when the user explicitly asks about a specific branch.',
                            ],
                        ],
                        'required' => [
                            'date_range',
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_low_stock_products',
                    'description' => 'Find products with low available stock in the permitted branch.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'threshold' => [
                                'type' => 'integer',
                                'description' => 'Available quantity threshold. Defaults to 10.',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_stock_summary',
                    'description' => 'Get inventory totals and available stock summary.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
           [
    'type' => 'function',
    'function' => [
        'name' => 'get_top_selling_products',
        'description' => 'Get top-selling products by quantity sold for each requested branch. Use branch_ids when comparing multiple branches.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'date_range' => [
                    'type' => 'string',
                    'enum' => [
                        'today',
                        'yesterday',
                        'last_7_days',
                        'this_month',
                    ],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Number of products per branch, maximum 10.',
                ],
                'branch_ids' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'integer',
                    ],
                    'description' => 'Branch IDs to compare. Admin only, and only branches belonging to the users store.',
                ],
            ],
            'required' => ['date_range'],
        ],
    ],
],
        ];

        $response = Http::withToken(config('services.groq.api_key'))
            ->post(
                config('services.groq.base_url') . '/chat/completions',
                [
                    'model' => 'openai/gpt-oss-20b',
                    'messages' => $messages,
                    'tools' => $tools,
                    'tool_choice' => 'auto',
                    'temperature' => 0.2,
                ]
            );

        if ($response->failed()) {
            return response()->json([
                'status' => false,
                'message' => 'AI request failed.',
                'error' => $response->json(),
            ], $response->status());
        }

        $assistantMessage = $response->json('choices.0.message');

        if (!empty($assistantMessage['tool_calls'])) {
            $messages[] = $assistantMessage;

            foreach ($assistantMessage['tool_calls'] as $toolCall) {
                $functionName = $toolCall['function']['name'];

                $arguments = json_decode(
                    $toolCall['function']['arguments'],
                    true
                );

                if (!is_array($arguments)) {
                    $arguments = [];
                }

                if ($functionName === 'get_sales_summary') {
                    $toolResult = $this->getSalesSummary(
                        $salesReportService,
                        $user,
                        $branchId,
                        $arguments
                    );
                } elseif ($functionName === 'get_low_stock_products') {
                    $toolResult = $this->getLowStockProducts(
                        $user,
                        $branchId,
                        $arguments
                    );
                } elseif ($functionName === 'get_stock_summary') {
                    $toolResult = $this->getStockSummary(
                        $user,
                        $branchId
                    );
                } elseif ($functionName === 'get_top_selling_products') {
                    $toolResult = $this->getTopSellingProducts(
                        $salesReportService,
                        $user,
                        $branchId,
                        $arguments
                    );
                } else {
                    $toolResult = [
                        'error' => 'Unknown tool.',
                    ];
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'name' => $functionName,
                    'content' => json_encode(
                        $toolResult,
                        JSON_UNESCAPED_UNICODE
                    ),
                ];
            }

            $finalResponse = Http::withToken(
                config('services.groq.api_key')
            )->post(
                config('services.groq.base_url') . '/chat/completions',
                [
                    'model' => 'openai/gpt-oss-20b',
                    'messages' => $messages,
                    'tools' => $tools,
                    'tool_choice' => 'none',
                    'temperature' => 0.2,
                ]
            );

            if ($finalResponse->failed()) {
                return response()->json([
                    'status' => false,
                    'message' => 'AI final response failed.',
                    'error' => $finalResponse->json(),
                ], $finalResponse->status());
            }

            return response()->json([
                'status' => true,
                'message' => $finalResponse->json(
                    'choices.0.message.content'
                ),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => $assistantMessage['content'] ?? '',
        ]);
    }

private function getSalesSummary(
        SalesReportService $salesReportService,
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        $dateRange = $arguments['date_range'] ?? 'today';

        $requestedBranchId = $arguments['branch_id'] ?? null;

        if ($user->role === 'manager') {
            $requestedBranchId = $branchId;
        }

        if ($user->role === 'admin' && $requestedBranchId) {
            $this->validateAdminBranch(
                $user,
                (int) $requestedBranchId
            );

            $branchId = (int) $requestedBranchId;
        }

        $filters = $salesReportService->resolveFilters([
            'date_range' => $dateRange,
            'bill_status' => 'all',
            'store_id' => $user->store_id,
            'branch_id' => $branchId,
        ]);

        $kpis = $salesReportService->getKpis($filters);

        return [
            'date_range' => $dateRange,
            'branch_id' => $branchId,
            'total_bills' => (int) ($kpis['total_bills'] ?? 0),
            'gross_sales' => (float) ($kpis['gross_sales'] ?? 0),
            'total_cogs' => (float) ($kpis['total_cogs'] ?? 0),
            'total_profit' => (float) ($kpis['total_profit'] ?? 0),
            'total_collected' => (float) ($kpis['total_collected'] ?? 0),
            'total_due' => (float) ($kpis['total_due'] ?? 0),
            'total_gst' => (float) ($kpis['total_gst_bills'] ?? 0),
        ];
    }
private function resolveBranchId(
        $user,
        $requestedBranchId = null
     ): ?int {
        if ($user->role === 'manager') {
            if (!empty($user->branch_id)) {
                return (int) $user->branch_id;
            }

            return DB::table('branch_staff')
                ->where('user_id', $user->id)
                ->value('branch_id');
        }

        if ($user->role === 'admin' && $requestedBranchId) {
            $this->validateAdminBranch(
                $user,
                (int) $requestedBranchId
            );

            return (int) $requestedBranchId;
        }

        return null;
    }

    private function validateAdminBranch(
        $user,
        int $branchId
    ): void {
        $exists = DB::table('branches')
            ->where('id', $branchId)
            ->where('store_id', $user->store_id)
            ->exists();

        if (!$exists) {
            abort(403, 'Invalid branch access.');
        }
    }
private function getLowStockProducts(
    $user,
    ?int $branchId,
    array $arguments
 ): array {
    $threshold = max(
        0,
        min((int) ($arguments['threshold'] ?? 10), 100000)
    );

    $query = DB::table('inventories as i')
        ->join('products as p', 'p.id', '=', 'i.product_id')
        ->join('branches as b', 'b.id', '=', 'i.branch_id')
        ->where('b.store_id', $user->store_id)
        ->whereNull('i.deleted_at')
        ->whereRaw(
            '(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) <= ?',
            [$threshold]
        )
        ->whereRaw(
            '(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) > 0'
        );

    if ($branchId) {
        $query->where('i.branch_id', $branchId);
    }

    $products = $query
        ->selectRaw('
            p.id as product_id,
            p.name as product_name,
            i.branch_id,
            SUM(
                i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)
            ) as available_qty
        ')
        ->groupBy('p.id', 'p.name', 'i.branch_id')
        ->havingRaw('SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) <= ?', [
            $threshold,
        ])
        ->orderBy('available_qty')
        ->limit(50)
        ->get();

    return [
        'threshold' => $threshold,
        'count' => $products->count(),
        'products' => $products->toArray(),
    ];
}

private function getStockSummary(
    $user,
    ?int $branchId
 ): array {
    $query = DB::table('inventories as i')
        ->join('branches as b', 'b.id', '=', 'i.branch_id')
        ->where('b.store_id', $user->store_id)
        ->whereNull('i.deleted_at');

    if ($branchId) {
        $query->where('i.branch_id', $branchId);
    }

    $summary = $query
        ->selectRaw('
            COUNT(DISTINCT i.product_id) as product_count,
            COALESCE(SUM(i.qty), 0) as total_qty,
            COALESCE(SUM(i.sold_qty), 0) as total_sold_qty,
            COALESCE(SUM(i.expired_qty), 0) as total_expired_qty,
            COALESCE(
                SUM(
                    i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)
                ), 0
            ) as available_qty
        ')
        ->first();

    return [
        'product_count' => (int) $summary->product_count,
        'total_qty' => (float) $summary->total_qty,
        'total_sold_qty' => (float) $summary->total_sold_qty,
        'total_expired_qty' => (float) $summary->total_expired_qty,
        'available_qty' => (float) $summary->available_qty,
    ];
}

private function getTopSellingProducts(
    SalesReportService $salesReportService,
    $user,
    ?int $branchId,
    array $arguments
): array {
    $dateRange = $arguments['date_range'] ?? 'this_month';

    if (!in_array($dateRange, [
        'today',
        'yesterday',
        'last_7_days',
        'this_month',
    ], true)) {
        $dateRange = 'this_month';
    }

    $limit = max(
        1,
        min((int) ($arguments['limit'] ?? 5), 10)
    );

    $requestedBranchIds = $arguments['branch_ids'] ?? [];

    if (!is_array($requestedBranchIds)) {
        $requestedBranchIds = [];
    }

    if ($user->role === 'manager') {
        // Manager sirf apni assigned branch ka data dekh sakta hai.
        $branchIds = $branchId ? [$branchId] : [];
    } else {
        // Admin ke liye requested branches validate karo.
        $branchIds = array_values(array_unique(array_map(
            'intval',
            $requestedBranchIds
        )));

        foreach ($branchIds as $requestedId) {
            $this->validateAdminBranch($user, $requestedId);
        }

        // Agar branch IDs specify nahi ki gayi hain,
        // toh current branch context use karo.
        if (empty($branchIds) && $branchId) {
            $branchIds = [$branchId];
        }
    }

    if (empty($branchIds)) {
        return [
            'error' => 'No authorized branch selected.',
            'products_by_branch' => [],
        ];
    }

    $results = [];

    foreach ($branchIds as $id) {
        $filters = $salesReportService->resolveFilters([
            'date_range' => $dateRange,
            'bill_status' => 'all',
            'store_id' => $user->store_id,
            'branch_id' => $id,
        ]);

        $performance = $salesReportService->getProductPerformance(
            $filters
        );

        $products = collect($performance['rows'])
            ->sortByDesc('qty_sold')
            ->take($limit)
            ->values()
            ->map(fn ($product) => [
                'product_id' => $product->product_id,
                'product_name' => $product->product_name,
                'qty_sold' => (float) $product->qty_sold,
                'revenue' => (float) $product->net_revenue,
                'profit' => (float) $product->total_profit,
            ]);

        $results[] = [
            'branch_id' => $id,
            'date_range' => $dateRange,
            'ranking_basis' => 'quantity_sold',
            'products' => $products->all(),
        ];
    }

    return [
        'date_range' => $dateRange,
        'ranking_basis' => 'quantity_sold',
        'products_by_branch' => $results,
    ];
}

private function systemPrompt(
        $user,
        ?int $branchId
    ): string {
        $scope = $user->role === 'manager'
            ? "The user is a manager and can only access branch {$branchId}."
            : 'The user is an admin and can access branches belonging to their store.';

        return <<<PROMPT
 You are the AI Business Assistant for a POS system.

 You answer questions about the user's actual business data.

 Important rules:

 1. Never invent sales, stock, purchase, profit or financial numbers.
 2. When the user asks about actual business data, use the available business tools.
 3. The database result is the source of truth.
 4. Give concise and easy-to-understand answers.
 5. Always respond in Roman Hindi / Hinglish.
 6. Use English letters only. Do not use Hindi/Devanagari script.
 7. Speak naturally and conversationally, like a helpful person talking to the user.
 8. Match the user's conversational style, but always write Hindi using English letters.

 User role:
 {$user->role}

 Store ID:
 {$user->store_id}

 Data access:
 {$scope}
 PROMPT;
    }
}
