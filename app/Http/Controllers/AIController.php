<?php

namespace App\Http\Controllers;

use App\Services\SalesReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class AIController extends Controller
{

public function chat(
    Request $request,
    SalesReportService $salesReportService,
    \App\Services\AIProviderService $aiProviderService
 ) {
    $request->validate([
        'message' => ['required', 'string', 'max:2000'],
        'branch_id' => ['nullable', 'integer', 'min:0'],
        'history' => ['nullable', 'array', 'max:20'],
        'history.*.role' => ['required', 'in:user,assistant'],
        'history.*.content' => ['required', 'string', 'max:2000'],
    ]);

    $user = Auth::user();

    if (!$user) {
        return response()->json([
            'status' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }

    if (!in_array($user->role, ['admin', 'manager'], true)) {
        return response()->json([
            'status' => false,
            'message' => 'You are not authorized to use the AI business assistant.',
        ], 403);
    }

    // Admin ke liye branch_id = 0 means all branches.
    $requestedBranchId = $request->input('branch_id');

    if ($user->role === 'admin' && (int) $requestedBranchId === 0) {
        $requestedBranchId = null;
    }

    $branchId = $this->resolveBranchId(
        $user,
        $requestedBranchId
    );


    // Deterministically fetch product names for list-style inventory questions.
    // This avoids depending on the LLM to decide whether to call the inventory tool.
    $latestMessage = trim((string) $request->input('message', ''));

    // Report/download requests must go to the AI + report tool, not the product list shortcut.
    $isReportRequest = preg_match(
        '/\b(report|download|export|pdf|excel|xlsx)\b/iu',
        $latestMessage
    ) === 1;

    $isProductListRequest = preg_match(
        '/\b(saare|sare|sabhi|sab|all|list|show|naam|names?)\b.{0,80}\b(products?|items?|inventory)\b|\b(products?|items?)\b.{0,80}\b(naam|names?|list|saare|sare|sabhi|sab|all)\b/iu',
        $latestMessage
    ) === 1;

    if ($isProductListRequest && !$isReportRequest) {
        try {
            // Shortcut replies are fixed text, so pick English vs Hinglish from the user's message.
            $isEnglish = str_contains($this->languageDirective($latestMessage), 'English only');

            $inventory = $this->getProductStockDetails(
                $user,
                $branchId,
                ['page' => 1, 'per_page' => 100]
            );

            if (!empty($inventory['error'])) {
                return response()->json([
                    'status' => false,
                    'message' => $inventory['error'],
                ], 422);
            }

            $products = $inventory['products'] ?? [];
            if (empty($products)) {
                return response()->json([
                    'status' => true,
                    'message' => $isEnglish
                        ? 'No products were found in your selected data scope.'
                        : 'Aapke selected data scope mein koi product nahi mila.',
                    'data' => $inventory,
                ]);
            }

            $table = "| Product Name | Total Qty | Sold Qty | Expired Qty | Available Qty |\n";
            $table .= "|---|---:|---:|---:|---:|\n";
            foreach ($products as $product) {
                $name = str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], (string) ($product['product_name'] ?? 'Unnamed product'));
                $table .= '| ' . $name
                    . ' | ' . ($product['total_qty'] ?? 0)
                    . ' | ' . ($product['sold_qty'] ?? 0)
                    . ' | ' . ($product['expired_qty'] ?? 0)
                    . ' | ' . ($product['available_qty'] ?? 0)
                    . " |\n";
            }

            $total = (int) ($inventory['total_products'] ?? count($products));
            $message = ($isEnglish
                ? "There are {$total} products in your selected data scope.\n\n"
                : "Aapke selected data scope mein total {$total} products hain.\n\n") . $table;
            if (($inventory['total_pages'] ?? 1) > 1) {
                $message .= $isEnglish
                    ? "\nThese are the first 100 products. Total pages: " . $inventory['total_pages'] . '. Ask for the next page to see more.'
                    : "\nYe pehle 100 products hain. Total pages: " . $inventory['total_pages'] . '. Agli list ke liye next page maangiye.';
            }

            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => $inventory,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json([
                'status' => false,
                'message' => 'Product inventory load nahi ho payi. Server logs mein database error check karein.',
            ], 500);
        }
    }

    $history = collect($request->input('history', []))
        ->take(-10)
        ->map(fn ($item) => [
            'role' => $item['role'],
            'content' => $item['content'],
        ])
        ->values()
        ->all();

    $messages = array_merge(
        [
            [
                'role' => 'system',
                'content' => $this->systemPrompt($user, $branchId),
            ],
        ],
        $history,
        [
            [
                'role' => 'user',
                // The language directive is only sent to the AI. The user never sees it
                // and the original message is what gets saved in the chat history.
                'content' => $request->message
                    . "\n\n(Instruction for the assistant, never mention it: "
                    . $this->languageDirective((string) $request->message) . ')',
            ],
        ]
    );

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
                    'required' => ['date_range'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'prepare_sales_report_download',
                'description' => 'Use ONLY when the user explicitly asks to download/export/get a sales report file. Prepares a download button for the frontend. Do not use for normal sales questions.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'format' => [
                            'type' => 'string',
                            'enum' => ['pdf', 'excel'],
                            'description' => 'Use excel ONLY if the user said excel/xlsx/sheet. Otherwise use pdf.',
                        ],
                        'date_range' => [
                            'type' => 'string',
                            'enum' => ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'custom'],
                            'description' => 'Use custom for a specific month, year or date range, and then fill date_from and date_to.',
                        ],
                        'date_from' => [
                            'type' => 'string',
                            'description' => 'YYYY-MM-DD, only for custom.',
                        ],
                        'date_to' => [
                            'type' => 'string',
                            'description' => 'YYYY-MM-DD, only for custom.',
                        ],
                    ],
                    'required' => ['date_range'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'prepare_report_download',
                'description' => 'Use ONLY when the user explicitly asks to download/export a NON-sales report file: stock/inventory report, low stock report, out of stock report, supplier dues report, top selling products report, or purchase suggestions report. Prepares a download button for the frontend. For SALES reports use prepare_sales_report_download instead.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'report_type' => [
                            'type' => 'string',
                            'enum' => ['inventory', 'low_stock', 'out_of_stock', 'supplier_dues', 'top_selling', 'purchase_suggestions', 'product_profit', 'branch_comparison', 'purchase_report'],
                            'description' => 'inventory = full stock list, low_stock = products running low, out_of_stock = zero stock products, supplier_dues = supplier purchase/paid/outstanding, top_selling = best selling products for a period, purchase_suggestions = what to reorder, product_profit = product-wise revenue and profit for a period, branch_comparison = all branches sales/profit comparison for a period (admin only), purchase_report = purchase bills with paid and due for a period.',
                        ],
                        'format' => [
                            'type' => 'string',
                            'enum' => ['pdf', 'excel'],
                            'description' => 'Use excel ONLY if the user said excel/xlsx/sheet. Otherwise use pdf.',
                        ],
                        'date_range' => [
                            'type' => 'string',
                            'enum' => ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'custom'],
                            'description' => 'Only for top_selling, product_profit, branch_comparison and purchase_report (default this_month). Other reports are current snapshots and ignore this.',
                        ],
                        'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD, only for period reports with custom range.'],
                        'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD, only for period reports with custom range.'],
                        'threshold' => ['type' => 'integer', 'description' => 'Only for low_stock. Default 10.'],
                        'limit' => ['type' => 'integer', 'description' => 'Only for top_selling. Number of products, default 20, max 100.'],
                        'cover_days' => ['type' => 'integer', 'description' => 'Only for purchase_suggestions. Default 7.'],
                    ],
                    'required' => ['report_type'],
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
                'name' => 'get_product_stock_details',
                'description' => 'Get a detailed list of all products with their exact available stock quantities. Use this whenever the user asks for all products, product names with quantities, item-wise stock, or a detailed inventory list. Do not use only get_stock_summary for these requests.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'page' => [
                            'type' => 'integer',
                            'description' => 'Page number, default 1.',
                        ],
                        'per_page' => [
                            'type' => 'integer',
                            'description' => 'Products per page, maximum 100.',
                        ],
                        'search' => [
                            'type' => 'string',
                            'description' => 'Optional product name or SKU search.',
                        ],
                    ],
                    'required' => [],
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
                            'items' => ['type' => 'integer'],
                            'description' => 'Branch IDs to compare. Admin only, and only branches belonging to the users store.',
                        ],
                    ],
                    'required' => ['date_range'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_out_of_stock_products',
                'description' => 'Find products with zero or negative available stock. Use this when user asks for out-of-stock or unavailable products.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'branch_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Optional branch IDs for admin branch comparison.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_purchase_suggestions',
                'description' => 'Suggest products to reorder based on last 30 days sales and current available stock. Do not invent quantities.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'branch_id' => [
                            'type' => ['integer', 'null'],
                            'description' => 'Optional numeric branch ID, only when the user explicitly provides or selects an ID.',
                        ],
                        'branch_name' => [
                            'type' => 'string',
                            'description' => 'Branch name explicitly mentioned by the user, for example LOYALITY MART. For admins, backend resolves the name to an authorized branch ID within their store. Never ask for a numeric ID if a branch name was provided. If no branch was named, ask the user for a branch name, not a numeric ID.',
                        ],
                        'product_name' => [
                            'type' => 'string',
                            'description' => 'Optional product name the user wants reorder suggestions for, e.g. XYZ-EDITED. This is a product name, not a branch name.',
                        ],
                        'cover_days' => [
                            'type' => 'integer',
                            'description' => 'Suggested stock coverage days; default 7.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_branches',
                'description' => 'List/count branches belonging to the current admin store. Use whenever the user asks how many branches they have, branch names, or wants to identify a branch. Managers may only see their assigned branch.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_sales_comparison',
                'description' => 'Compare current month with previous month, today with yesterday, or sales across authorized branches.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'comparison' => [
                            'type' => 'string',
                            'enum' => [
                                'today_vs_yesterday',
                                'this_month_vs_last_month',
                                'branches',
                            ],
                        ],
                        'branch_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                        ],
                    ],
                    'required' => ['comparison'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_payment_summary',
                'description' => 'Get actual cash, online, wallet, split-payment and outstanding due summary for a period.',
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
                    ],
                    'required' => ['date_range'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_supplier_summary',
                'description' => 'Get supplier-wise purchase totals, paid amounts, outstanding dues and supplier count. Use for questions about suppliers, supplier balances, or who is owed money.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date_range' => [
                            'type' => 'string',
                            'enum' => ['all_time', 'this_month'],
                            'description' => 'Period for purchase totals. Use all_time for current outstanding balances.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_supplier_details',
                'description' => 'Get one supplier contact details, purchase bills, total purchased, paid and due amounts. Use when user asks about a named supplier or their purchase history.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'supplier_name' => [
                            'type' => 'string',
                            'description' => 'Supplier name mentioned by the user.',
                        ],
                    ],
                    'required' => ['supplier_name'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_supplier_rate_comparison',
                'description' => 'Compare recorded purchase rates for a product across suppliers. Use for questions about cheapest supplier or previous purchase rates.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_name' => [
                            'type' => 'string',
                            'description' => 'Product name mentioned by the user.',
                        ],
                    ],
                    'required' => ['product_name'],
                ],
            ],
        ],
    ];

    $download = null;
    $maxToolRounds = 4;

    for ($round = 0; $round <= $maxToolRounds; $round++) {
        try {
            $response = $aiProviderService->complete([
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => 'auto',
                'temperature' => 0.2,
            ], 'chat');
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'AI request failed. Please try again.',
            ], 502);
        }

        if ($response->failed()) {
            report(new \RuntimeException(
                'AI chat completion failed with HTTP status ' . $response->status()
            ));

            return response()->json([
                'status' => false,
                'message' => 'AI request failed. Please try again.',
            ], 502);
        }

        $assistantMessage = $response->json('choices.0.message');

        if (!is_array($assistantMessage)) {
            return response()->json([
                'status' => false,
                'message' => 'AI returned an invalid response.',
            ], 502);
        }

        $toolCalls = $assistantMessage['tool_calls'] ?? [];

        if (empty($toolCalls)) {
            return response()->json([
                'status' => true,
                'message' => $assistantMessage['content'] ?? '',
                // Only set when the user actually asked for a report file.
                'download' => $download,
            ]);
        }

        if ($round === $maxToolRounds) {
            return response()->json([
                'status' => false,
                'message' => 'AI analysis reached its tool-call limit. Please ask a more specific question.',
            ], 422);
        }

        // Assistant tool-call message must precede tool results.
        $messages[] = $assistantMessage;

        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['function']['name'] ?? '';

            $arguments = json_decode(
                $toolCall['function']['arguments'] ?? '{}',
                true
            );

            if (!is_array($arguments)) {
                $arguments = [];
            }

            try {
                $toolResult = $this->executeBusinessTool(
                    $functionName,
                    $arguments,
                    $salesReportService,
                    $user,
                    $branchId
                );
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $toolResult = ['error' => $exception->getMessage()];
            } catch (\Throwable $exception) {
                report($exception);

                $toolResult = [
                    'error' => 'This business report could not be generated. Please try again.',
                ];
            }

            if (
                in_array($functionName, ['prepare_sales_report_download', 'prepare_report_download'], true)
                && !empty($toolResult['download'])
            ) {
                $download = $toolResult['download'];
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'] ?? '',
                'name' => $functionName,
                'content' => json_encode(
                    $toolResult,
                    JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
                ) ?: '{"error":"Unable to encode tool result."}',
            ];
        }
    }

    return response()->json([
        'status' => false,
        'message' => 'AI could not complete the request.',
    ], 502);
}


    /**
     * Decides which language/script the AI must reply in, based ONLY on the user's latest message.
     * The result is appended to the user message (AI-only) so the model cannot drift
     * to the language of earlier history or of the system prompt examples.
     */
    private function languageDirective(string $text): string
    {
        // Gujarati script
        if (preg_match('/\p{Gujarati}/u', $text)) {
            return 'Reply language: Gujarati, written in Gujarati script.';
        }

        // Hindi / Marathi etc. in Devanagari
        if (preg_match('/\p{Devanagari}/u', $text)) {
            return 'Reply language: the same language as this message (Hindi/Marathi), written in Devanagari script.';
        }

        // Any other non-Latin script (Tamil, Bengali, Arabic, ...)
        if (preg_match('/(?!\p{Latin})\p{L}/u', $text)) {
            return 'Reply language: the same language and script as this message.';
        }

        // Hindi / Gujarati / other Indian language typed in Roman letters
        if (preg_match('/\b(hai|hain|kya|kitna|kitni|kitne|aaj|aaje|kal|batao|bataiye|dikhao|dikha|mujhe|mere|meri|mera|kaise|kyun|kyu|nahi|nahin|haan|chahiye|karo|karna|mein|aur|wala|wali|sabhi|saare|chhe|chho|ketli|ketla|kem|tame|maru|tamaru|mare|mane|shu|joie|batavo)\b/iu', $text)) {
            return 'Reply language: the same language as this message (Hinglish or another Indian language typed in Roman letters), written in Roman letters only. Do not switch to English or to a native script.';
        }

        return 'Reply language: English only. Do not use Hindi, Hinglish or Gujarati words, even if earlier messages in this conversation were in another language.';
    }

    private function executeBusinessTool(
        string $functionName,
        array $arguments,
        SalesReportService $salesReportService,
        $user,
        ?int $branchId
     ): array {
        switch ($functionName) {
            case 'get_sales_summary':
                return $this->getSalesSummary(
                    $salesReportService,
                    $user,
                    $branchId,
                    $arguments
                );
            case 'prepare_sales_report_download':
                return $this->prepareSalesReportDownload(
                    $user,
                    $branchId,
                    $arguments
                );
            case 'prepare_report_download':
                return $this->prepareReportDownload(
                    $user,
                    $branchId,
                    $arguments
                );
            case 'get_low_stock_products':
                return $this->getLowStockProducts($user, $branchId, $arguments);
            case 'get_stock_summary':
                return $this->getStockSummary($user, $branchId);
            case 'get_top_selling_products':
                return $this->getTopSellingProducts(
                    $salesReportService,
                    $user,
                    $branchId,
                    $arguments
                );
            case 'get_out_of_stock_products':
                return $this->getOutOfStockProducts($user, $branchId, $arguments);
            case 'get_purchase_suggestions':
                return $this->getPurchaseSuggestions(
                    $salesReportService,
                    $user,
                    $branchId,
                    $arguments
                );
            case 'get_branches':
                return $this->getBranches($user, $branchId);
            case 'get_sales_comparison':
                return $this->getSalesComparison(
                    $salesReportService,
                    $user,
                    $branchId,
                    $arguments
                );
            case 'get_payment_summary':
                return $this->getPaymentSummary(
                    $salesReportService,
                    $user,
                    $branchId,
                    $arguments
                );
            case 'get_supplier_summary':
                return $this->getSupplierSummary($user, $branchId, $arguments);
            case 'get_supplier_details':
                return $this->getSupplierDetails($user, $branchId, $arguments);
            case 'get_supplier_rate_comparison':
                return $this->getSupplierRateComparison($user, $branchId, $arguments);
            case 'get_product_stock_details':
                return $this->getProductStockDetails(
                    $user,
                    $branchId,
                    $arguments
                );
            default:
                return ['error' => 'Unknown business tool.'];
        }
    }

    /**
     * Prepares the data the frontend needs to show a download button.
     * Format is excel only if the user asked for it, otherwise pdf.
     */
    private function prepareSalesReportDownload(
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }

        $format = ($arguments['format'] ?? 'pdf') === 'excel' ? 'excel' : 'pdf';
        $range = $arguments['date_range'] ?? 'this_month';
        $today = Carbon::today();

        switch ($range) {
            case 'today':
                $from = $today->copy();
                $to = $today->copy();
                break;
            case 'yesterday':
                $from = $today->copy()->subDay();
                $to = $from->copy();
                break;
            case 'last_7_days':
                $from = $today->copy()->subDays(6);
                $to = $today->copy();
                break;
            case 'last_month':
                $from = $today->copy()->subMonthNoOverflow()->startOfMonth();
                $to = $from->copy()->endOfMonth();
                break;
            case 'custom':
                try {
                    $from = Carbon::createFromFormat(
                        'Y-m-d',
                        (string) ($arguments['date_from'] ?? '')
                    )->startOfDay();
                    $to = Carbon::createFromFormat(
                        'Y-m-d',
                        (string) ($arguments['date_to'] ?? '')
                    )->startOfDay();
                } catch (\Throwable $e) {
                    return ['error' => 'Valid date range nahi mili. Month ya dates batayein.'];
                }
                if ($from->gt($to)) {
                    return ['error' => 'Start date end date se badi nahi ho sakti.'];
                }
                break;
            default: // this_month
                $from = $today->copy()->startOfMonth();
                $to = $today->copy();
                break;
        }

        return [
            'download' => [
                'report_type' => 'sales',
                'title' => 'Sales Report',
                'format' => $format,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'branch_id' => $branchId ?? 0,
            ],
            'message' => 'Download button user ko dikhaya jayega. Short confirmation do, report ka data chat mein paste mat karo.',
        ];
    }

    /**
     * Prepares a download button for non-sales reports.
     * Snapshot reports (stock etc.) use today's date; top_selling uses the requested period.
     */
    private function prepareReportDownload(
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }

        $titles = [
            'inventory' => 'Stock Report',
            'low_stock' => 'Low Stock Report',
            'out_of_stock' => 'Out of Stock Report',
            'supplier_dues' => 'Supplier Dues Report',
            'top_selling' => 'Top Selling Products Report',
            'purchase_suggestions' => 'Purchase Suggestions Report',
            'product_profit' => 'Profit Report (Product-wise)',
            'branch_comparison' => 'Branch Comparison Report',
            'purchase_report' => 'Purchase Report',
        ];

        $type = (string) ($arguments['report_type'] ?? '');
        if (!isset($titles[$type])) {
            return ['error' => 'Ye report type supported nahi hai.'];
        }

        if ($type === 'purchase_suggestions' && !$branchId) {
            return ['error' => 'Purchase suggestions report ke liye pehle ek branch select karein.'];
        }

        $format = ($arguments['format'] ?? 'pdf') === 'excel' ? 'excel' : 'pdf';
        $today = Carbon::today();
        $from = $today->copy();
        $to = $today->copy();

        if ($type === 'branch_comparison' && $user->role !== 'admin') {
            return ['error' => 'Branch comparison report sirf admin download kar sakta hai.'];
        }

        if (in_array($type, ['top_selling', 'product_profit', 'branch_comparison', 'purchase_report'], true)) {
            $range = $arguments['date_range'] ?? 'this_month';
            switch ($range) {
                case 'today':
                    break;
                case 'yesterday':
                    $from = $today->copy()->subDay();
                    $to = $from->copy();
                    break;
                case 'last_7_days':
                    $from = $today->copy()->subDays(6);
                    break;
                case 'last_month':
                    $from = $today->copy()->subMonthNoOverflow()->startOfMonth();
                    $to = $from->copy()->endOfMonth();
                    break;
                case 'custom':
                    try {
                        $from = Carbon::createFromFormat('Y-m-d', (string) ($arguments['date_from'] ?? ''))->startOfDay();
                        $to = Carbon::createFromFormat('Y-m-d', (string) ($arguments['date_to'] ?? ''))->startOfDay();
                    } catch (\Throwable $e) {
                        return ['error' => 'Valid date range nahi mili. Month ya dates batayein.'];
                    }
                    if ($from->gt($to)) {
                        return ['error' => 'Start date end date se badi nahi ho sakti.'];
                    }
                    break;
                default: // this_month
                    $from = $today->copy()->startOfMonth();
                    break;
            }
        }

        $options = [];
        if ($type === 'low_stock') {
            $options['threshold'] = max(0, min((int) ($arguments['threshold'] ?? 10), 100000));
        }
        if ($type === 'top_selling') {
            $options['limit'] = max(1, min((int) ($arguments['limit'] ?? 20), 100));
        }
        if ($type === 'purchase_suggestions') {
            $options['cover_days'] = max(1, min((int) ($arguments['cover_days'] ?? 7), 30));
        }

        return [
            'download' => [
                'report_type' => $type,
                'title' => $titles[$type],
                'format' => $format,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'branch_id' => $branchId ?? 0,
                'options' => $options,
            ],
            'message' => 'Download button user ko dikhaya jayega. Short confirmation do, report ka data chat mein paste mat karo.',
        ];
    }

    private function getSalesSummary(
        SalesReportService $salesReportService,
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        $dateRange = $arguments['date_range'] ?? 'today';
        if (!in_array($dateRange, [
            'today',
            'yesterday',
            'last_7_days',
            'this_month',
        ], true)) {
            $dateRange = 'today';
        }
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }
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
    private function getProductStockDetails(
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        if ($user->role === 'manager' && !$branchId) {
            return [
                'error' => 'Manager ki assigned branch resolve nahi hui.',
            ];
        }

        $page = max(1, (int) ($arguments['page'] ?? 1));
        $perPage = max(
            1,
            min((int) ($arguments['per_page'] ?? 50), 100)
        );

        $search = trim((string) ($arguments['search'] ?? ''));

        $query = DB::table('inventories as i')
            ->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('b.store_id', $user->store_id)
            ->whereNull('i.deleted_at');

        if ($branchId !== null) {
            $query->where('i.branch_id', $branchId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                    ->orWhere('p.sku', 'like', "%{$search}%");
            });
        }

        // Count distinct products, not inventory rows or branch/product groups.
        $totalProducts = (clone $query)->distinct()->count('p.id');

        // For an admin viewing all branches, aggregate quantities by product.
        // A manager or branch-specific query is already restricted to one branch.
        $branchSelect = $branchId === null
            ? 'NULL as branch_id'
            : 'MAX(i.branch_id) as branch_id';

        $products = $query
            ->selectRaw("
                {$branchSelect},
                p.id as product_id,
                p.name as product_name,
                COALESCE(MAX(i.selling_price), 0) as selling_price,
                SUM(i.qty) as total_qty,
                SUM(i.sold_qty) as sold_qty,
                SUM(COALESCE(i.expired_qty, 0)) as expired_qty,
                SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) as available_qty
            ")
            ->groupBy('p.id', 'p.name')
            ->orderBy('p.name')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn ($product) => [
                'branch_id' => $product->branch_id !== null ? (int) $product->branch_id : null,
                'product_id' => (int) $product->product_id,
                'product_name' => $product->product_name,
                'total_qty' => (float) $product->total_qty,
                'sold_qty' => (float) $product->sold_qty,
                'expired_qty' => (float) $product->expired_qty,
                'available_qty' => (float) $product->available_qty,
                'selling_price' => (float) $product->selling_price,
            ]);

        return [
            'products' => $products->all(),
            'total_products' => $totalProducts,
            'current_page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($totalProducts / $perPage),
            'branch_id' => $branchId,

            'note' => 'Available quantity is total quantity minus sold quantity minus expired quantity.',
            'description' => 'Get product-wise inventory details including exact product name, selling price and available quantity. Always use this tool when the user asks for a table of products with selling prices and stock quantities.',
        ];
    }
    private function resolveBranchId(
        $user,
        $requestedBranchId = null
     ): ?int {
        if ($user->role === 'manager') {
            if (!empty($user->branch_id)) {
                $branchId = (int) $user->branch_id;
            } else {
                $branchId = DB::table('branch_staff')
                    ->where('user_id', $user->id)
                    ->value('branch_id');
                $branchId = $branchId !== null ? (int) $branchId : null;
            }
            if ($branchId !== null) {
                $this->validateAdminBranch($user, $branchId);
            }
            return $branchId;
        }
        if ($user->role === 'admin' && $requestedBranchId !== null) {
            $this->validateAdminBranch($user, (int) $requestedBranchId);
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
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }
        $threshold = max(0, min((int) ($arguments['threshold'] ?? 10), 100000));
        $query = DB::table('inventories as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->where('b.store_id', $user->store_id)
            ->whereNull('i.deleted_at');
        if ($branchId !== null) {
            $query->where('i.branch_id', $branchId);
        }
        $products = $query
            ->selectRaw('
                p.id as product_id,
                p.name as product_name,
                i.branch_id,
                SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) as available_qty
            ')
            ->groupBy('p.id', 'p.name', 'i.branch_id')
            ->havingRaw(
                'SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) > 0'
            )
            ->havingRaw(
                'SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) <= ?',
                [$threshold]
            )
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
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }
        $query = DB::table('inventories as i')
            ->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->where('b.store_id', $user->store_id)
            ->whereNull('i.deleted_at');
        if ($branchId !== null) {
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
        if (!in_array($user->role, ['admin', 'manager'], true)) {
            return ['error' => 'You are not authorized to view sales analytics.'];
        }
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }
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
            $branchIds = [$branchId];
        } else {
            $branchIds = array_values(array_unique(array_map(
                'intval',
                $requestedBranchIds
            )));
            foreach ($branchIds as $requestedId) {
                if ($requestedId < 1) {
                    return ['error' => 'Invalid branch ID.'];
                }
                $this->validateAdminBranch($user, $requestedId);
            }
            if (empty($branchIds) && $branchId !== null) {
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
                ->map(fn($product) => [
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

    private function getOutOfStockProducts(
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }
        if (!in_array($user->role, ['admin', 'manager'], true)) {
            return ['error' => 'You are not authorized to view inventory.'];
        }
        $requestedIds = $arguments['branch_ids'] ?? [];
        if (!is_array($requestedIds)) {
            $requestedIds = [];
        }
        if ($user->role === 'manager') {
            $branchIds = [$branchId];
        } else {
            $branchIds = array_values(array_unique(array_map('intval', $requestedIds)));
            foreach ($branchIds as $id) {
                if ($id < 1) {
                    return ['error' => 'Invalid branch ID.'];
                }
                $this->validateAdminBranch($user, $id);
            }
            if (empty($branchIds) && $branchId !== null) {
                $branchIds = [$branchId];
            }
        }
        $query = DB::table('inventories as i')
            ->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->where('b.store_id', $user->store_id)
            ->whereNull('i.deleted_at');
        if (!empty($branchIds)) {
            $query->whereIn('i.branch_id', $branchIds);
        }
        $rows = $query
            ->selectRaw('
                i.branch_id,
                i.product_id,
                COALESCE(p.name, CONCAT("Deleted product #", i.product_id)) as product_name,
                SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) as available_qty
            ')
            ->groupBy('i.branch_id', 'i.product_id', 'p.name')
            ->havingRaw(
                'SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0)) <= 0'
            )
            ->orderBy('available_qty')
            ->limit(100)
            ->get();
        return [
            'branch_ids' => $branchIds,
            'products' => $rows->toArray(),
            'count' => $rows->count(),
        ];
    }

    private function getBranches($user, ?int $branchId): array
    {
        if ($user->role === 'manager') {
            if (!$branchId) {
                return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
            }
            $branches = DB::table('branches')
                ->where('store_id', $user->store_id)
                ->where('id', $branchId)
                ->select('id', 'name')
                ->get();
        } else {
            $branches = DB::table('branches')
                ->where('store_id', $user->store_id)
                ->select('id', 'name')
                ->orderBy('name')
                ->get();
        }

        return [
            'count' => $branches->count(),
            'branches' => $branches->map(fn($branch) => [
                'branch_id' => (int) $branch->id,
                'branch_name' => $branch->name,
            ])->values()->all(),
        ];
    }

    private function getPurchaseSuggestions(
        SalesReportService $salesReportService,
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        $requestedBranchId = $arguments['branch_id'] ?? $branchId;
        $requestedBranchName = trim((string) ($arguments['branch_name'] ?? ''));
        $requestedProductName = trim((string) ($arguments['product_name'] ?? ''));

        if ($user->role === 'manager') {
            // Managers are always restricted to their assigned branch.
            $requestedBranchId = $branchId;
        } else {
            if (!$requestedBranchId && $requestedBranchName !== '') {
                // Resolve a named branch only within the current admin's store.
                $matches = DB::table('branches')
                    ->where('store_id', $user->store_id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($requestedBranchName)])
                    ->select('id', 'name')
                    ->get();

                if ($matches->count() === 1) {
                    $requestedBranchId = (int) $matches->first()->id;
                } elseif ($matches->count() > 1) {
                    return [
                        'error' => 'Multiple branches have this exact name. Ask the user to choose one.',
                        'matching_branches' => $matches->map(fn($branch) => [
                            'branch_id' => (int) $branch->id,
                            'branch_name' => $branch->name,
                        ])->values()->all(),
                    ];
                } else {
                    $similar = DB::table('branches')
                        ->where('store_id', $user->store_id)
                        ->where('name', 'like', '%' . addcslashes($requestedBranchName, '%_\\') . '%')
                        ->select('id', 'name')
                        ->limit(10)
                        ->get();

                    return [
                        'error' => 'Could not find an exact branch name in this store. Ask the user to confirm the branch name.',
                        'matching_branches' => $similar->map(fn($branch) => [
                            'branch_id' => (int) $branch->id,
                            'branch_name' => $branch->name,
                        ])->values()->all(),
                    ];
                }
            }

            if ($requestedBranchId) {
                $this->validateAdminBranch($user, (int) $requestedBranchId);
            }
        }

        if (!$requestedBranchId) {
            return [
                'error' => 'Purchase suggestions require one branch. If the admin named a branch, resolve that branch name within the admin store. Otherwise ask the admin to select or name a branch.',
            ];
        }
        $coverDays = max(
            1,
            min((int) ($arguments['cover_days'] ?? 7), 30)
        );
        $from = Carbon::today()->subDays(29)->startOfDay();
        $to = Carbon::today()->endOfDay();
        $filters = $salesReportService->resolveFilters([
            'date_range' => 'custom',
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'bill_status' => 'all',
            'store_id' => $user->store_id,
            'branch_id' => (int) $requestedBranchId,
        ]);
        $performance = $salesReportService->getProductPerformance($filters);
        $salesByProduct = collect($performance['rows'])
            ->keyBy('product_id');
        $stockRows = DB::table('inventories as i')
            ->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->where('b.store_id', $user->store_id)
            ->where('i.branch_id', (int) $requestedBranchId)
            ->whereNull('i.deleted_at')
            ->selectRaw('
            i.product_id,
            COALESCE(p.name, CONCAT("Deleted product #", i.product_id))
                as product_name,
            SUM(i.qty - i.sold_qty - COALESCE(i.expired_qty, 0))
                as available_qty
        ')
            ->groupBy('i.product_id', 'p.name')
            ->get();
        $suggestions = $stockRows->map(function ($stock) use (
            $salesByProduct,
            $coverDays
        ) {
            $sold = (float) ($salesByProduct->get($stock->product_id)->qty_sold ?? 0);
            $available = max(0, (float) $stock->available_qty);
            $dailyDemand = $sold / 30;
            $targetQty = (int) ceil($dailyDemand * $coverDays);
            $reorderQty = max(0, $targetQty - $available);
            return [
                'product_id' => $stock->product_id,
                'product_name' => $stock->product_name,
                'sold_last_30_days' => $sold,
                'available_qty' => $available,
                'average_daily_demand' => round($dailyDemand, 2),
                'cover_days' => $coverDays,
                'suggested_reorder_qty' => $reorderQty,
            ];
        });
        if ($requestedProductName !== '') {
            $suggestions = $suggestions->filter(function ($item) use ($requestedProductName) {
                return mb_stripos($item['product_name'], $requestedProductName) !== false;
            });
            if ($suggestions->isEmpty()) {
                $matchingProducts = $stockRows
                    ->filter(fn($stock) => mb_stripos($stock->product_name, $requestedProductName) !== false)
                    ->pluck('product_name')
                    ->unique()
                    ->take(10)
                    ->values()
                    ->all();
                return [
                    'branch_id' => (int) $requestedBranchId,
                    'branch_name' => DB::table('branches')->where('id', (int) $requestedBranchId)->where('store_id', $user->store_id)->value('name'),
                    'product_name_searched' => $requestedProductName,
                    'suggestions' => [],
                    'matching_products' => $matchingProducts,
                    'message' => 'No reorder suggestion was found for this product. It may not match a product in this branch or its calculated reorder quantity is zero.',
                ];
            }
        }
        $suggestions = $suggestions
            ->filter(fn($item) => $item['suggested_reorder_qty'] > 0)
            ->sortByDesc('suggested_reorder_qty')
            ->take(50)
            ->values();
        $resolvedBranchName = DB::table('branches')
            ->where('id', (int) $requestedBranchId)
            ->where('store_id', $user->store_id)
            ->value('name');

        return [
            'branch_id' => (int) $requestedBranchId,
            'branch_name' => $resolvedBranchName,
            'period' => 'last_30_days',
            'product_name_searched' => $requestedProductName !== '' ? $requestedProductName : null,
            'suggestions' => $suggestions->all(),
        ];
    }

    private function getSalesComparison(
        SalesReportService $salesReportService,
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }
        $comparison = $arguments['comparison'] ?? 'this_month_vs_last_month';
        if ($comparison === 'branches') {
            if ($user->role !== 'admin') {
                return ['error' => 'Branch comparison is available to admins only.'];
            }
            $rawIds = $arguments['branch_ids'] ?? [];
            if (!is_array($rawIds)) {
                return ['error' => 'Invalid branch IDs.'];
            }
            $branchIds = array_values(array_unique(array_map('intval', $rawIds)));
            foreach ($branchIds as $id) {
                if ($id < 1) {
                    return ['error' => 'Invalid branch ID.'];
                }
                $this->validateAdminBranch($user, $id);
            }
            if (count($branchIds) < 2) {
                return ['error' => 'Kam se kam 2 authorized branches select karein.'];
            }
            $results = [];
            foreach ($branchIds as $id) {
                $filters = $salesReportService->resolveFilters([
                    'date_range' => 'this_month',
                    'bill_status' => 'all',
                    'store_id' => $user->store_id,
                    'branch_id' => $id,
                ]);
                $results[] = [
                    'branch_id' => $id,
                    'summary' => $salesReportService->getKpis($filters),
                ];
            }
            return [
                'comparison' => 'branches',
                'date_range' => 'this_month',
                'results' => $results,
            ];
        }
        if ($comparison === 'today_vs_yesterday') {
            $currentRange = 'today';
            $previousRange = 'yesterday';
        } else {
            $comparison = 'this_month_vs_last_month';
            $today = Carbon::today();
            $currentRange = [
                'from' => $today->copy()->startOfMonth(),
                'to' => $today->copy(),
            ];
            $previousStart = $today->copy()
                ->subMonthNoOverflow()
                ->startOfMonth();
            $previousEnd = $previousStart->copy()->day(
                min($today->day, $previousStart->daysInMonth)
            );
            $previousRange = [
                'from' => $previousStart,
                'to' => $previousEnd,
            ];
        }
        $makeFilters = function ($range) use (
            $salesReportService,
            $user,
            $branchId
        ) {
            $input = [
                'bill_status' => 'all',
                'store_id' => $user->store_id,
                'branch_id' => $branchId,
            ];
            if (is_array($range)) {
                $input['date_range'] = 'custom';
                $input['date_from'] = $range['from']->toDateString();
                $input['date_to'] = $range['to']->toDateString();
            } else {
                $input['date_range'] = $range;
            }
            return $salesReportService->resolveFilters($input);
        };
        $current = $salesReportService->getKpis($makeFilters($currentRange));
        $previous = $salesReportService->getKpis($makeFilters($previousRange));
        $currentSales = (float) ($current['gross_sales'] ?? 0);
        $previousSales = (float) ($previous['gross_sales'] ?? 0);
        return [
            'comparison' => $comparison,
            'period_basis' => $comparison === 'this_month_vs_last_month'
                ? 'month_to_date_vs_same_days_previous_month'
                : 'today_vs_yesterday',
            'current_period' => $current,
            'previous_period' => $previous,
            'sales_difference' => round($currentSales - $previousSales, 2),
            'sales_change_pct' => $previousSales > 0
                ? round((($currentSales - $previousSales) / $previousSales) * 100, 2)
                : null,
            'note' => $previousSales > 0
                ? null
                : 'Previous period ki sales zero hai; percentage growth calculate nahi ki gayi.',
        ];
    }

    private function getPaymentSummary(
        SalesReportService $salesReportService,
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }
        $dateRange = $arguments['date_range'] ?? 'this_month';
        if (!in_array($dateRange, [
            'today',
            'yesterday',
            'last_7_days',
            'this_month',
        ], true)) {
            $dateRange = 'this_month';
        }
        $filters = $salesReportService->resolveFilters([
            'date_range' => $dateRange,
            'bill_status' => 'all',
            'store_id' => $user->store_id,
            'branch_id' => $branchId,
        ]);
        return [
            'date_range' => $dateRange,
            'branch_id' => $branchId,
            'payments' => $salesReportService->getPaymentSummary($filters),
            'payment_methods' => $salesReportService->getPaymentMethods($filters),
            'totals' => $salesReportService->getKpis($filters),
        ];
    }

    private function getSupplierSummary(
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }
        $dateRange = $arguments['date_range'] ?? 'all_time';
        if (!in_array($dateRange, ['all_time', 'this_month'], true)) {
            $dateRange = 'all_time';
        }
        $suppliers = DB::table('suppliers')
            ->where('store_id', $user->store_id)
            ->orderBy('name')
            ->get();
        $results = $suppliers->map(function ($supplier) use (
            $user,
            $branchId,
            $dateRange
        ) {
            $baseQuery = DB::table('purchase_bills')
                ->where('store_id', $user->store_id)
                ->where('supplier_id', $supplier->id)
                ->whereNull('deleted_at');
            if ($branchId !== null) {
                $baseQuery->where('branch_id', $branchId);
            }
            $allBills = (clone $baseQuery)->get(['id', 'total_amount']);
            $allBillIds = $allBills->pluck('id');
            $totalPaid = $allBillIds->isEmpty()
                ? 0.0
                : (float) DB::table('purchase_payments')
                    ->whereIn('purchase_bill_id', $allBillIds)
                    ->where('status', 'success')
                    ->sum('amount');
            $periodQuery = clone $baseQuery;
            if ($dateRange === 'this_month') {
                $periodQuery->whereBetween('bill_date', [
                    Carbon::today()->startOfMonth()->toDateString(),
                    Carbon::today()->endOfMonth()->toDateString(),
                ]);
            }
            $periodBills = $periodQuery->get(['id', 'total_amount']);
            $allTimePurchase = (float) $allBills->sum('total_amount');
            return [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'purchase_total' => round(
                    (float) $periodBills->sum('total_amount'),
                    2
                ),
                'all_time_purchase_total' => round($allTimePurchase, 2),
                'total_paid' => round($totalPaid, 2),
                'outstanding_due' => round(
                    max(0, $allTimePurchase - $totalPaid),
                    2
                ),
                'bill_count' => $allBills->count(),
            ];
        })->values();
        return [
            'period' => $dateRange,
            'branch_id' => $branchId,
            'supplier_count' => $suppliers->count(),
            'suppliers' => $results->all(),
            'note' => 'Outstanding is recorded bill totals minus successful payments. Returns, credit notes and supplier advances are not included unless reflected in those records.',
        ];
    }

    private function getSupplierDetails(
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }
        $name = trim((string) ($arguments['supplier_name'] ?? ''));
        if ($name === '') {
            return ['error' => 'Supplier name required hai.'];
        }
        $supplier = DB::table('suppliers')
            ->where('store_id', $user->store_id)
            ->where('name', 'like', '%' . $name . '%')
            ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$name])
            ->first();
        if (!$supplier) {
            return ['message' => 'Is naam ka supplier nahi mila.'];
        }
        $baseQuery = DB::table('purchase_bills')
            ->where('store_id', $user->store_id)
            ->where('supplier_id', $supplier->id)
            ->whereNull('deleted_at');
        if ($branchId !== null) {
            $baseQuery->where('branch_id', $branchId);
        }
        $allBills = (clone $baseQuery)->get(['id', 'total_amount']);
        $allBillIds = $allBills->pluck('id');
        $allTimePaid = $allBillIds->isEmpty()
            ? 0.0
            : (float) DB::table('purchase_payments')
                ->whereIn('purchase_bill_id', $allBillIds)
                ->where('status', 'success')
                ->sum('amount');
        $recentBills = (clone $baseQuery)
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        $recentIds = $recentBills->pluck('id');
        $paidByBill = $recentIds->isEmpty()
            ? collect()
            : DB::table('purchase_payments')
            ->whereIn('purchase_bill_id', $recentIds)
            ->where('status', 'success')
            ->selectRaw('purchase_bill_id, SUM(amount) as paid')
            ->groupBy('purchase_bill_id')
            ->pluck('paid', 'purchase_bill_id');
        $history = $recentBills->map(function ($bill) use ($paidByBill) {
            $paid = (float) ($paidByBill[$bill->id] ?? 0);
            $total = (float) $bill->total_amount;
            return [
                'bill_no' => $bill->bill_no,
                'bill_date' => $bill->bill_date,
                'total_amount' => round($total, 2),
                'paid_amount' => round($paid, 2),
                'due_amount' => round(max(0, $total - $paid), 2),
            ];
        });
        $allTimePurchase = (float) $allBills->sum('total_amount');
        return [
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contact' => $supplier->contact,
                'gstin' => $supplier->gstin,
                'address' => $supplier->address,
                'state' => $supplier->state,
            ],
            'branch_id' => $branchId,
            'all_time_bill_count' => $allBills->count(),
            'all_time_purchase_total' => round($allTimePurchase, 2),
            'all_time_paid_amount' => round($allTimePaid, 2),
            'all_time_outstanding_due' => round(
                max(0, $allTimePurchase - $allTimePaid),
                2
            ),
            'bills_returned' => $history->count(),
            'purchase_history' => $history->all(),
            'note' => 'All-time totals cover all recorded bills in the authorized scope. History contains only the latest 50 bills.',
        ];
    }

    private function getSupplierRateComparison(
        $user,
        ?int $branchId,
        array $arguments
     ): array {
        if ($user->role === 'manager' && !$branchId) {
            return ['error' => 'Manager ki assigned branch resolve nahi hui.'];
        }
        $productName = trim((string) ($arguments['product_name'] ?? ''));
        if ($productName === '') {
            return ['error' => 'Product name required hai.'];
        }
        $query = DB::table('purchase_lines as pl')
            ->join('purchase_bills as pb', 'pb.id', '=', 'pl.purchase_bill_id')
            ->join('products as p', 'p.id', '=', 'pl.product_id')
            ->join('suppliers as s', 's.id', '=', 'pb.supplier_id')
            ->where('pb.store_id', $user->store_id)
            ->where('s.store_id', $user->store_id)
            ->whereNull('pb.deleted_at')
            ->where('p.name', 'like', '%' . $productName . '%');
        if ($branchId !== null) {
            $query->where('pb.branch_id', $branchId);
        }
        $rows = $query
            ->select([
                'p.id as product_id',
                'p.name as product_name',
                's.id as supplier_id',
                's.name as supplier_name',
                'pl.purchase_rate',
                'pb.bill_no',
                'pb.bill_date',
            ])
            ->orderByDesc('pb.bill_date')
            ->orderByDesc('pb.id')
            ->limit(500)
            ->get();
        if ($rows->isEmpty()) {
            return [
                'message' => 'Is product ki purchase history nahi mili.',
                'product_name_searched' => $productName,
                'comparisons' => [],
            ];
        }
        $comparisons = $rows
            ->groupBy('product_id')
            ->map(function ($productRows) {
                $supplierRates = $productRows
                    ->groupBy('supplier_id')
                    ->map(function ($supplierRows) {
                        $latest = $supplierRows->first();
                        $rates = $supplierRows->pluck('purchase_rate')
                            ->map(fn($rate) => (float) $rate);
                        return [
                            'supplier_id' => $latest->supplier_id,
                            'supplier_name' => $latest->supplier_name,
                            'latest_purchase_rate' => round((float) $latest->purchase_rate, 2),
                            'lowest_recorded_rate' => round($rates->min(), 2),
                            'highest_recorded_rate' => round($rates->max(), 2),
                            'average_recorded_rate' => round($rates->avg(), 2),
                            'last_purchase_date' => $latest->bill_date,
                            'last_bill_no' => $latest->bill_no,
                            'purchase_records_in_returned_rows' => $supplierRows->count(),
                        ];
                    })
                    ->sortBy('latest_purchase_rate')
                    ->values();
                return [
                    'product_id' => $productRows->first()->product_id,
                    'product_name' => $productRows->first()->product_name,
                    'suppliers' => $supplierRates->all(),
                ];
            })
            ->values();
        return [
            'product_name_searched' => $productName,
            'comparisons' => $comparisons->all(),
            'note' => 'Historical rates only, not current quotations. Aggregates are based on the latest 500 matching purchase rows returned by the query.',
        ];
    }

public function scanPurchaseBill(
    Request $request,
    \App\Services\AIProviderService $aiProviderService
) {
    $request->validate([
        'bill_image' => [
            'required',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:10240',
        ],
    ]);

    $user = Auth::user();

    if (!$user || !in_array($user->role, ['admin', 'manager'], true)) {
        return response()->json([
            'status' => false,
            'message' => 'You are not authorized to scan purchase bills.',
        ], 403);
    }

    $file = $request->file('bill_image');
    $mime = $file->getMimeType();

    $imageData = base64_encode(
        file_get_contents($file->getRealPath())
    );

    $prompt = <<<'PROMPT'
Read this supplier purchase invoice and extract its data.

Return ONLY valid JSON using this structure:
{
  "supplier_name": null,
  "bill_no": null,
  "bill_date": null,
  "tax_type": "exclusive",
  "total_amount": null,
  "lines": [
    {
      "product_name": null,
      "qty": null,
      "free_qty": 0,
      "purchase_rate": null,
      "mrp": null,
      "selling_price": null,
      "gst_rate_percent": null,
      "hsn_code": null,
      "batch_no": null,
      "expiry_date": null,
      "discount": 0,
      "discount_type": null
    }
  ]
}

Rules:
- Do not invent values. Use null when unreadable or missing.
- Use YYYY-MM-DD for dates when the date is clear.
- Return numeric values as numbers.
- Use "inclusive" or "exclusive" only when supported by the invoice;
  otherwise use null.
- Extract each product row separately.
- Do not treat the invoice total as a product rate.
- Do not create or save any purchase bill.
PROMPT;

    try {
        $response = $aiProviderService->complete([
            'temperature' => 0,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mime};base64,{$imageData}",
                            ],
                        ],
                    ],
                ],
            ],
        ], 'vision');

        if ($response->failed()) {
            report(new \RuntimeException(
                'Purchase bill AI scan failed: HTTP ' . $response->status()
            ));

            return response()->json([
                'status' => false,
                'message' => 'Could not scan the bill. Please try again.',
            ], 502);
        }

        $content = $response->json('choices.0.message.content');

        $content = preg_replace(
            '/^```(?:json)?\s*|\s*```$/i',
            '',
            trim((string) $content)
        );

        $bill = json_decode($content, true);

        if (
            !is_array($bill) ||
            !isset($bill['lines']) ||
            !is_array($bill['lines'])
        ) {
            return response()->json([
                'status' => false,
                'message' => 'AI could not read the bill correctly. Try a clearer image.',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'data' => $bill,
        ]);
    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'status' => false,
            'message' => 'Bill scanning failed. Please try again.',
        ], 500);
    }
}


    private function systemPrompt(
        $user,
        ?int $branchId
    ): string {
        $scope = $user->role === 'manager'
            ? "The user is a manager and can only access branch {$branchId}."
            : 'The user is an admin and can access all branches belonging to their store. If no specific branch is explicitly requested, aggregate data across ALL branches in the store.';
        $today = Carbon::today()->toDateString();
        return <<<PROMPT
       You are the AI Business Assistant for a POS system.
                You answer questions about the user's actual business data.
                Today's date: {$today}
                Important rules:
        1. Never invent sales, stock, purchase, profit or financial numbers.
        2. When the user asks about actual business data, use the available business tools.
        3. The database result is the source of truth.
        4. Give concise, clear and easy-to-understand answers.
        5. LANGUAGE RULE (mandatory): Reply in the same language AND the same script as the user's latest message. Decide this only from the user's latest message, never from earlier history, tool results or these instructions.
        6. Mapping: English message -> English reply. Hinglish (Hindi typed in Roman letters) -> Hinglish in Roman letters. Hindi typed in Devanagari -> Hindi in Devanagari. Gujarati typed in Gujarati script -> Gujarati script. Gujarati typed in Roman letters -> Gujarati language written in Roman letters (never switch to English or Hindi). The same applies to Marathi, Punjabi, Bengali, Tamil, Telugu, Kannada, Malayalam, Urdu and any other language: same language, same script as the user typed.
        7. If the user explicitly asks for a language (for example "Gujarati mein batao" or "answer in Hindi"), use that language. Write it in the script the user is typing in: Roman letters if the message is in Roman letters, native script if the message is in native script.
        8. For mixed messages, use the language that dominates the message. Very short follow-ups such as "ok", "yes", "haan", "1" keep the language and script of your previous reply.
        9. Never translate or change data returned by tools: product names, branch names, supplier names, SKUs, dates, numbers and amounts stay exactly as returned. Table headings and all explanation text follow the user's language. Markdown tables must stay valid.
        10. Short confirmations (for example report ready), "data not found" messages and error explanations must also follow the user's language and script.
        11. Before sending the final answer, silently check that the language and script match the user's latest message. If not, rewrite the answer.
        12. Examples:
            - User: "HOW MUCH SALE TODAY?" -> "Today's total sales are ₹0.00. No bills were processed today."
            - User: "AAJ KI SALE KITNI HAI?" -> "Aaj ki total sale ₹0.00 hai. Aaj koi bill process nahi hua."
            - User: "आज की सेल कितनी है?" -> "आज की कुल सेल ₹0.00 है। आज कोई बिल नहीं बना।"
            - User: "આજની સેલ કેટલી છે?" -> "આજની કુલ સેલ ₹0.00 છે. આજે કોઈ બિલ બન્યું નથી."
            - User: "Aaj ni sale ketli chhe?" (Gujarati in Roman letters) -> "Aaj ni total sale ₹0.00 chhe. Aaje koi bill banyu nathi."
        13. For zero available stock, always call get_out_of_stock_products.
        14. For reorder suggestions, always call get_purchase_suggestions. Explain that recommendations are estimates based on the last 30 days of sales. Purchase suggestions require one branch because inventory and demand are branch-specific. If an admin names a branch, pass that exact branch name in branch_name; never ask for a numeric ID. If no branch is named, ask which branch by its NAME, not numeric ID. Never silently choose a branch or combine branches for purchase suggestions. Managers use their assigned branch, regardless of any branch name in the message. Distinguish the product name from the branch name: if the user says 'XYZ-EDITED ke liye product purchase suggestion', treat XYZ-EDITED as product_name, not branch_name. If product name is given but no branch is given, ask which branch name to use.
        14a. If the user asks how many branches they have or asks to list branch names, call get_branches and answer from its count/list. Do not say there is no branch-listing tool.
        15. For date or branch comparisons, always call get_sales_comparison.
        16. For payment method breakdown or outstanding dues, always call get_payment_summary.
        17. Never invent product names, sales values, payment amounts, or reorder quantities.
        18. When a tool returns an error or no data, report that result honestly.
        19. Use conversation history only to understand follow-up questions. Always fetch current business numbers using tools.
        20. For supplier count, purchase totals, monthly purchases and supplier outstanding dues, always use get_supplier_summary.
        21. For a supplier's contact details, individual bills or purchase history, use get_supplier_details.
        22. To compare recorded purchase rates across suppliers, use get_supplier_rate_comparison.
        23. Supplier balances, purchase totals and rates must come only from tool results. Never guess missing values.
        24. Distinguish purchase totals, payments made and outstanding dues. Do not treat them as the same amount.
        25. For monthly reports, use monthly bill counts and monthly purchase totals. Do not confuse them with all-time values.
        26. Use totals returned by the backend whenever available. Do not independently invent or alter financial totals.
        27. Managers may access transaction data only for their resolved assigned branch.
        27a. For admins, when no specific branch is explicitly requested, answer using combined totals across all branches in their store. Do not require a branch_id for store-wide questions like 'Aaj ki total sale kitni hai?'. Only use a specific branch_id when the user clearly asks about one branch. A frontend branch_id of 0 means all branches, not an invalid branch.
        28. Describe supplier rate comparisons as historical recorded rates, not current market quotations.
        29. Keep currency in the format used by the business, normally ₹ for INR. Do not convert currency unless explicitly requested.
        30. Never claim that a transaction occurred unless it is supported by the database tool result.
        31. You are an AI assistant specifically for this POS application. Keep answers focused on the POS system and its features.
        32. If the user asks a general programming question unrelated to the POS project, politely explain that you are designed to help with this POS application.
        33. Do not provide unrelated programming tutorials, generic code examples, HTML pages, or external project solutions unless the user explicitly asks for help implementing something in this POS project.
        34. If the user asks how a programming concept works within this POS project, explain it using the existing project's code and architecture.
        35. Never assume that a feature, file, API, table, or functionality exists in the project unless it is supported by the available project context.
        36. Keep answers concise and relevant to the user's current POS application.
        37. When the user asks for detailed inventory, all product names with quantities, or product-wise stock, always call get_product_stock_details. Present product_name and available_qty clearly. Never answer these questions using only aggregate stock totals.
        38. If the inventory tool returns multiple pages, explain the total product count and offer to show the next page. Never claim that one page contains every product if more pages exist.
        39. When the user asks for product name, selling price and quantity in a table, call get_product_stock_details and return a Markdown table with Product Name, Selling Price, and Available Quantity. Use only values returned by the tool.
        40. When the user asks to download, export or get a sales REPORT (file), call prepare_sales_report_download. Use format "excel" only if the user mentioned Excel/xlsx/sheet; otherwise use "pdf". For a month or year (for example "September 2026" or "2026"), use date_range "custom" with exact date_from and date_to. If no period is mentioned, use this_month. After the tool call, reply in one short line in the user's language and script (for example, English: "Your sales report is ready, download it using the button below.") and never paste the report data. If the user asks for CSV or Word, say only PDF and Excel are supported and prepare a PDF. If the tool returns an error, report it honestly and do not claim a download is ready.
        41. For normal sales questions (how much sold, today's sales etc.) do NOT call prepare_sales_report_download.
        42. For downloading/exporting stock or inventory, low stock, out of stock, supplier dues, top selling products, purchase suggestions, product-wise profit, branch comparison or purchase bills as a file, call prepare_report_download with the matching report_type (use prepare_sales_report_download only for SALES reports). Choose format "excel" only if the user mentioned Excel/xlsx/sheet, otherwise "pdf". After the tool call reply in one short line in the user's language and script and never paste the report data. Only call it when the user asks for a downloadable report/file; for normal questions like "which products are low on stock" use the normal tools and answer in chat.
        43. SUPPORTED downloadable reports (PDF or Excel): Sales report, Stock report, Low stock report, Out of stock report, Supplier dues report, Top selling products report, Purchase suggestions report, Profit report (product-wise), Branch comparison report (admin only), Purchase report. If the user asks to download a report that is NOT in this list (for example GST report, customer report, expense report, expiry report, staff report, payment method report, or any other custom report), do NOT call any tool and do NOT invent data. Reply briefly in the user's language and script that this report is not available for download yet, and list the available reports above so they can pick one. If the user asks for Word or CSV, say only PDF and Excel are supported. If the nearest available report could still help, suggest it.

        **TABLE FORMATTING RULES**

        1. When the user asks for data in a table, always format the response as a clean Markdown table.
        2. Use clear, descriptive column headers based on the actual data returned by the business tools.
        3. For product inventory, use columns: Product Name, Total Qty, Sold Qty, Expired Qty, Available Qty.
        4. For sales reports, use relevant columns such as Date, Total Bills, Sales, Collected Amount, Due Amount and Profit, only when those fields are available.
        5. For supplier reports, use Supplier Name, Purchase Total, Paid Amount and Outstanding Due, when available.
        6. Never invent missing values. If a value is unavailable, display “N/A”.
        7. Align currency and numeric values consistently.
        8. After the table, provide a short summary with important totals when the backend provides them.
        9. If the user asks for a table, do not return the same data only as a paragraph or bullet list.
        10. Use Markdown table syntax and keep the response readable on desktop and mobile.

        11. Every Markdown table must contain:
            - One header row
            - One separator row, such as |---|---:|---|
            - One data row per record
        12. Never output table rows without a valid header separator.
        13. Never wrap Markdown tables in code fences.
        14. Keep the table as raw Markdown in the response text.
        15. Do not replace table formatting with pipe-separated plain text.

            User role:
            {$user->role}
            Store ID:
            {$user->store_id}
            Data access:
            {$scope}
      PROMPT;
    }
}