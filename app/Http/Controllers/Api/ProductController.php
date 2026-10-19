<?php

namespace App\Http\Controllers\Api;

use App\Exports\ProductTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\ProductRowsImport;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Milon\Barcode\Facades\DNS1DFacade as DNS1D;

class ProductController extends Controller
{
    public function getAllProducts(Request $request)
    {
        $user = Auth::user();
        $role = $user->role;
        $storeId = $user->store_id;

        $query = Product::where('store_id', $storeId)
            ->with([
                'brand',
                'category',
                'gstRate',
            ]);

        $branchId = null;

        if ($role === 'manager') {
            $branchId = $user->branch_id;

            if (! $branchId) {
                $branchId = DB::table('branch_staff')
                    ->where('user_id', $user->id)
                    ->value('branch_id');
            }

            if (! $branchId) {
                return response()->json([
                    'status' => true,
                    'products' => [],
                ], 200);
            }
        } elseif ($role === 'admin') {
            if ($request->filled('branch_id')) {
                $branchId = (int) $request->branch_id;
            }
        }

        $query->addSelect([
            'total_stock' => Inventory::selectRaw('COALESCE(SUM(qty - sold_qty), 0)')
                ->whereColumn('inventories.product_id', 'products.id')
                ->whereNull('inventories.deleted_at')
                ->when($branchId, fn ($q) => $q->where('inventories.branch_id', $branchId))
                ->whereColumn('inventories.sold_qty', '<', 'inventories.qty')
                ->where(function ($q) {
                    $q->whereNull('inventories.expiry_date')
                        ->orWhere('inventories.expiry_date', '>=', now()->toDateString());
                }),
        ]);

        $query->with([
            'inventories' => function ($inventoryQuery) use ($branchId) {
                $inventoryQuery->whereNull('deleted_at')
                    ->whereColumn('sold_qty', '<', 'qty')
                    ->where(function ($q) {
                        $q->whereNull('expiry_date')
                            ->orWhere('expiry_date', '>=', now()->toDateString());
                    });

                if ($branchId) {
                    $inventoryQuery->where('branch_id', $branchId);
                }
            },
        ]);

        $products = $query->get();

        return response()->json([
            'status' => true,
            'products' => $products,
        ], 200);
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $storeId = $user->store_id;
        if ($user->role === 'admin') {
            $storeBranchIds = Branch::where('store_id', $storeId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            if ($request->filled('branch_id')) {
                $requested = (int) $request->branch_id;
                $branchIds = in_array($requested, $storeBranchIds, true) ? [$requested] : [];
            } else {
                $branchIds = $storeBranchIds;
            }
        } else {
            $branchIds = $user->branches->pluck('id')->toArray();
        }

        $showOutOfStock = $request->boolean('show_out_of_stock', false);

        $products = Product::where('products.store_id', $storeId)
            ->when($request->category_id, function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            })
            ->when($request->brand_id, function ($q) use ($request) {
                $q->where('brand_id', $request->brand_id);
            })
            ->when($request->search, function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%');
            })
            ->with(['store', 'brand', 'category', 'gstRate'])
            ->with(['inventories' => function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds)
                    ->whereColumn('sold_qty', '<', 'qty')
                    ->where(function ($q2) {
                        $q2->whereNull('expiry_date')
                            ->orWhere('expiry_date', '>=', now()->toDateString());
                    })
                    ->orderBy('expiry_date', 'asc');
            }])
            ->addSelect([
                'total_stock' => Inventory::selectRaw('COALESCE(SUM(qty - sold_qty), 0)')
                    ->whereColumn('inventories.product_id', 'products.id')
                    ->whereIn('inventories.branch_id', $branchIds)
                    ->whereColumn('inventories.sold_qty', '<', 'inventories.qty')
                    ->where(function ($q) {
                        $q->whereNull('inventories.expiry_date')
                            ->orWhere('inventories.expiry_date', '>=', now()->toDateString());
                    }),
            ])
            ->addSelect([
                'nearest_expiry' => Inventory::selectRaw('MIN(expiry_date)')
                    ->whereColumn('inventories.product_id', 'products.id')
                    ->whereIn('inventories.branch_id', $branchIds)
                    ->whereColumn('inventories.sold_qty', '<', 'inventories.qty')
                    ->where('inventories.expiry_date', '>=', now()->toDateString()),
            ])
            ->addSelect([
                'last_purchase_bill_no' => Inventory::select('purchase_bills.inward_no')
                    ->join('purchase_bills', 'purchase_bills.id', '=', 'inventories.purchase_bill_id')
                    ->whereColumn('inventories.product_id', 'products.id')
                    ->whereIn('inventories.branch_id', $branchIds)
                    ->latest('inventories.created_at')
                    ->limit(1),
            ])
            ->orderByRaw('nearest_expiry IS NULL, nearest_expiry ASC')
            ->get();

        if (! $showOutOfStock) {
            $products = $products->filter(function ($product) {
                return (float) $product->total_stock > 0;
            })->values();
        }

        $products = $products->map(function ($product) {
            $groupedBatches = $product->inventories->groupBy('batch_no');

            $batches = $groupedBatches->map(function ($invGroup) {
                $first = $invGroup->first();

                $totalQty = $invGroup->sum('qty');
                $totalSoldQty = $invGroup->sum('sold_qty');
                $totalFree = $invGroup->sum('free');

                return [
                    'id' => $first->id,
                    'batch_no' => $first->batch_no,
                    'mrp' => $first->mrp,
                    'cost_price' => $first->cost_price,
                    'selling_price' => $first->selling_price,
                    'qty' => $totalQty,
                    'sold_qty' => $totalSoldQty,
                    'free' => $totalFree,
                    'qty_available' => $totalQty - $totalSoldQty, // Total available stock
                    'expiry_date' => $first->expiry_date,
                    'is_opening' => $first->is_opening,
                    'batch_barcode' => $first->batch_barcode,
                ];
            })->values();

            $prices = $batches->pluck('selling_price')->filter(fn ($p) => ! is_null($p));

            $data = $product->toArray();
            $data['min_price'] = $prices->min();
            $data['max_price'] = $prices->max();
            $data['has_multiple_prices'] = $prices->unique()->count() > 1;
            $data['batch_count'] = $batches->count();
            $data['batches'] = $batches;
            unset($data['inventories']);

            return $data;
        });

        return response()->json([
            'status' => true,
            'products' => $products,
        ], 200);
    }

    public function show($id)
    {
        $storeId = Auth::user()->store_id;
        $product = Product::where('store_id', $storeId)->where('id', $id)->first();

        if (! $product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'product' => $product,
        ], 200);
    }

    private function generateEan13Barcode()
    {
        // Generate first 12 digits
        $base = str_pad(mt_rand(0, 999999999999), 12, '0', STR_PAD_LEFT);

        // Calculate checksum
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        // Final barcode
        return $base.$checkDigit;
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'sku' => 'nullable|string|unique:products,sku',
                'barcode' => 'nullable|string', // This will be the Manufacturer barcode if scanned
                'is_price_override' => 'nullable|boolean',
                'sale_type' => 'nullable|in:unit,weight',
                'plu_code' => 'nullable|digits_between:1,5|required_if:sale_type,weight',
                'price_per_kg' => 'nullable|numeric|min:0|required_if:sale_type,weight',
            ]);

            $user = Auth::user();
            if (! in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $barcode = $request->barcode;

            if (empty($barcode)) {
                do {
                    $barcode = $this->generateEan13Barcode();
                    $exists = Product::where('barcode', $barcode)->exists();
                } while ($exists);
            } else {
                $exists = Product::where('barcode', $barcode)->exists();
                if ($exists) {
                    return response()->json(['status' => false, 'message' => 'Product with this barcode already exists'], 422);
                }
            }

            $product = Product::create([
                'store_id' => $storeId,
                'sku' => $request->sku,
                'barcode' => $barcode, // Master barcode
                'name' => $request->name,
                'brand_id' => $request->brand_id,
                'category_id' => $request->category_id,
                'hsn_code' => $request->hsn_code,
                'gst_rate_id' => $request->gst_rate_id,
                'gst_inclusive' => $request->gst_inclusive ?? 0,
                'is_price_override' => $request->input('is_price_override', 0),
                'sale_type' => $request->input('sale_type', 'unit'),
                'plu_code' => $request->plu_code,
                'price_per_kg' => $request->price_per_kg,
                'created_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Product created successfully',
                'product' => $product,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'is_price_override' => 'nullable|boolean',
                'barcode' => 'nullable|string|unique:products,barcode,'.$id,
                'sale_type' => 'nullable|in:unit,weight',
                'plu_code' => 'nullable|digits_between:1,5|required_if:sale_type,weight',
                'price_per_kg' => 'nullable|numeric|min:0|required_if:sale_type,weight',
            ]);

            $user = Auth::user();

            if (! in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $product = Product::where('store_id', $storeId)->where('id', $id)->first();

            if (! $product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            $product->update([
                'name' => $request->name,
                'sku' => $request->sku,
                'barcode' => $request->barcode,
                'brand_id' => $request->brand_id,
                'category_id' => $request->category_id,
                'hsn_code' => $request->hsn_code,
                'gst_rate_id' => $request->gst_rate_id,
                'gst_inclusive' => $request->gst_inclusive ?? 0,
                'is_price_override' => $request->input('is_price_override', 0),
                'sale_type' => $request->input('sale_type', $product->sale_type),
                'plu_code' => $request->plu_code,
                'price_per_kg' => $request->price_per_kg,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Product updated successfully',
                'product' => $product,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating the product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateSellingPrice(Request $request, $inventoryId)
    {
        try {
            $user = Auth::user();

            $request->validate([
                'selling_price' => 'required|numeric|min:0.01',
            ]);

            // Find inventory
            $inventory = Inventory::with('product')->find($inventoryId);

            if (! $inventory) {
                return response()->json([
                    'status' => false,
                    'message' => 'Inventory not found',
                ], 404);
            }

            // Check is_override from PRODUCTS table
            $product = $inventory->product;

            if (! $product || $product->store_id !== $user->store_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            // Manager/cashier can only override prices for their own assigned branch
            if (in_array($user->role, ['manager', 'cashier'], true)) {
                $allowedBranchIds = $user->branches()->pluck('branches.id')->toArray();
                if (! in_array((int) $inventory->branch_id, $allowedBranchIds, true)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - This inventory item does not belong to your branch',
                    ], 403);
                }
            }

            // Product must have is_override = 1
            if ((int) $product->is_price_override !== 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Price override is not allowed for this product',
                    'is_price_override' => 0,
                ], 403);
            }

            // Update price in INVENTORY table
            $inventory->selling_price = $request->selling_price;
            $inventory->save();

            return response()->json([
                'status' => true,
                'message' => 'Selling price updated successfully',

                'inventory_id' => $inventory->id,
                'product_id' => $product->id,

                // Updated inventory price
                'selling_price' => (float) $inventory->selling_price,

                // From PRODUCTS table
                'is_price_override' => (int) $product->is_price_override,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update selling price',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // public function destroy($id)
    // {
    //     try {
    //         $user = Auth::user();

    //         if (! in_array($user->role, ['admin', 'manager'])) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Unauthorized',
    //             ], 403);
    //         }

    //         $storeId = Auth::user()->store_id;

    //         $product = Product::where('store_id', $storeId)->where('id', $id)->first();

    //         if (! $product) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Product not found',
    //             ], 404);
    //         }

    //         $product->delete();

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Product deleted successfully',
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'An error occurred while deleting the product.',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function destroy($id)
    {
        try {
            $user = Auth::user();

            if (! in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $storeId = $user->store_id;

            $product = Product::where('store_id', $storeId)
                ->where('id', $id)
                ->first();

            if (! $product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            $hasInventory = DB::table('inventories')
                ->where('product_id', $product->id)
                ->exists();

            $hasPurchaseHistory = DB::table('purchase_lines')
                ->where('product_id', $product->id)
                ->exists();

            $hasSalesHistory = DB::table('sales_bill_lines')
                ->where('product_id', $product->id)
                ->exists();

            $hasSalesReturnHistory = DB::table('sales_return_lines')
                ->where('product_id', $product->id)
                ->exists();

            $hasPurchaseReturnHistory = DB::table('purchase_return_lines')
                ->where('product_id', $product->id)
                ->exists();

            if (
                $hasInventory ||
                $hasPurchaseHistory ||
                $hasSalesHistory ||
                $hasSalesReturnHistory ||
                $hasPurchaseReturnHistory
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'This product has inventory or transaction history and cannot be permanently deleted. Mark it inactive instead.',
                ], 409);
            }

            $product->delete();

            return response()->json([
                'status' => true,
                'message' => 'Product deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting the product.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function barcodeImage($id)
    {
        try {
            $storeId = Auth::user()->store_id;

            $product = Product::where('store_id', $storeId)->where('id', $id)->first();

            if (! $product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            // Validate barcode length
            if (strlen($product->barcode) !== 13) {
                return response()->json(['error' => 'Invalid EAN-13 barcode'], 422);
            }

            // Generate PNG (base64)
            $pngBase64 = DNS1D::getBarcodePNG($product->barcode, 'EAN13', 2.5, 80);
            $png = base64_decode($pngBase64);

            return response($png, 200)
                ->header('Content-Type', 'image/png')
                ->header('X-Product-Name', $product->name)
                ->header('X-Price', $product->selling_price)
                ->header('Content-Length', strlen($png));
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while generating barcode image.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getExpiredStock(Request $request)
    {
        $user = Auth::user();

        if (! in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
                'data' => [],
                'total_loss' => 0,
            ], 403);
        }

        $range = $request->input('date_range', 'all');
        $fromDate = null;
        $toDate = null;

        if ($range === 'custom') {
            if ($request->filled('date_from')) {
                $fromDate = Carbon::parse($request->date_from)->startOfDay();
            }
            if ($request->filled('date_to')) {
                $toDate = Carbon::parse($request->date_to)->endOfDay();
            }
        } elseif ($range !== 'all') {
            [$fromDate, $toDate] = match ($range) {
                'today' => [
                    Carbon::today()->startOfDay(),
                    Carbon::today()->endOfDay(),
                ],
                'yesterday' => [
                    Carbon::yesterday()->startOfDay(),
                    Carbon::yesterday()->endOfDay(),
                ],
                'last_7_days' => [
                    Carbon::now()->subDays(6)->startOfDay(),
                    Carbon::now()->endOfDay(),
                ],
                'this_month' => [
                    Carbon::now()->startOfMonth()->startOfDay(),
                    Carbon::now()->endOfDay(),
                ],
                default => [null, null],
            };
        }

        if ($request->filled('from_date')) {
            $fromDate = Carbon::parse($request->from_date)->startOfDay();
        }

        if ($request->filled('to_date')) {
            $toDate = Carbon::parse($request->to_date)->endOfDay();
        }

        if ($fromDate && ! $toDate) {
            $toDate = Carbon::now()->endOfDay();
        }

        if (! $fromDate && $toDate) {
            $fromDate = Carbon::create(2000, 1, 1)->startOfDay();
        }

        if ($user->role === 'admin') {
            $branchQuery = DB::table('branches')->where('store_id', $user->store_id);

            if ($request->filled('branch_id')) {
                $requestedBranchId = (int) $request->branch_id;
                $branchExists = $branchQuery->where('id', $requestedBranchId)->exists();

                if (! $branchExists) {
                    return response()->json([
                        'status' => false,
                        'message' => 'This branch does not belong to your store',
                        'data' => [],
                        'total_loss' => 0,
                    ], 403);
                }

                $branchIds = [$requestedBranchId];
            } else {
                $branchIds = $branchQuery->pluck('id')->toArray();
            }
        } else {
            $branchIds = $user->branches()->pluck('branches.id')->toArray();

            if ($request->filled('branch_id')) {
                $requestedBranchId = (int) $request->branch_id;

                if (! in_array($requestedBranchId, $branchIds, true)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'This branch is not assigned to you',
                        'data' => [],
                        'total_loss' => 0,
                    ], 403);
                }

                $branchIds = [$requestedBranchId];
            }
        }

        if (empty($branchIds)) {
            return response()->json([
                'status' => true,
                'message' => 'No branch available for this user',
                'data' => [],
                'total_loss' => 0,
            ], 200);
        }

        $query = Inventory::with([
            'product' => fn ($q) => $q->withTrashed()->select('id', 'name'),
            'branch:id,name',
        ])
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('expiry_date')
            ->whereColumn('sold_qty', '<', 'qty');

        if ($fromDate) {
            $query->whereDate('expiry_date', '>=', $fromDate->toDateString());
        }

        if ($toDate) {
            $query->whereDate('expiry_date', '<=', $toDate->toDateString());
        }

        if (! $fromDate && ! $toDate) {
            $query->where('expiry_date', '<', now()->toDateString());
        } else {
            $query->where('expiry_date', '<=', now()->toDateString());
        }

        $expiredBatches = $query
            ->select(
                'id',
                'product_id',
                'branch_id',
                'batch_no',
                'batch_barcode',
                'expiry_date',
                'cost_price',
                'selling_price',
                'qty',
                'sold_qty'
            )
            ->get()
            ->map(function ($item) {
                $expiredQty = $item->qty - $item->sold_qty;

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name ?? '—',
                    'branch_id' => $item->branch_id,
                    'branch_name' => $item->branch->name ?? '—',
                    'batch_no' => $item->batch_no,
                    'batch_barcode' => $item->batch_barcode,
                    'expiry_date' => $item->expiry_date,
                    'expired_qty' => $expiredQty,
                    'cost_price' => $item->cost_price,
                    'loss_amount' => $expiredQty * $item->cost_price,
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $expiredBatches,
            'total_loss' => $expiredBatches->sum('loss_amount'),
            'branch_id' => $request->branch_id ?? 'all',
            'from_date' => $fromDate?->toDateString(),
            'to_date' => $toDate?->toDateString(),
        ]);
    }

    private const IMPORT_MAX_ROWS = 2000;

    /**
     * Empty Excel template (headings only) so a new user knows the exact columns.
     */
    public function downloadImportTemplate()
    {
        $user = Auth::user();

        if (! $user || ! in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        return Excel::download(
            new ProductTemplateExport,
            'product-import-template.xlsx'
        );
    }

    public function importExcel(Request $request)
    {
        $user = Auth::user();

        if (! $user || ! in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
        ]);

        // Large files can take a while (row by row validation).
        set_time_limit(120);

        try {
            $storeId = $user->store_id;

            $sheetRows = Excel::toCollection(
                new ProductRowsImport,
                $request->file('file')
            )->first() ?? collect();

            // Drop completely empty rows, but remember the real Excel row number
            // (+2 because row 1 is the heading row).
            $items = [];
            foreach ($sheetRows as $index => $row) {
                $raw = $row->toArray();

                if (collect($raw)->every(
                    fn ($value) => $value === null || trim((string) $value) === ''
                )) {
                    continue;
                }

                $items[] = [
                    'row' => $index + 2,
                    'data' => $this->normalizeImportRow($raw),
                    'has_name_column' => array_key_exists('name', $raw),
                ];
            }

            if (empty($items)) {
                return response()->json([
                    'status' => false,
                    'message' => 'The file has no product rows.',
                ], 422);
            }

            if (! $items[0]['has_name_column']) {
                return response()->json([
                    'status' => false,
                    'message' => 'Column "name" was not found. Please download the template and keep its headings unchanged.',
                ], 422);
            }

            if (count($items) > self::IMPORT_MAX_ROWS) {
                return response()->json([
                    'status' => false,
                    'message' => 'Too many rows. Please import at most '.self::IMPORT_MAX_ROWS.' products at a time.',
                ], 422);
            }

            // One query per column instead of one query per row.
            $existingSkus = $this->existingImportValues('sku', array_column(array_column($items, 'data'), 'sku'));
            $existingBarcodes = $this->existingImportValues('barcode', array_column(array_column($items, 'data'), 'barcode'));

            // Brands / categories of this store, so each name is looked up only once.
            $brandIds = Brand::where('store_id', $storeId)->get(['id', 'name'])
                ->mapWithKeys(fn ($b) => [mb_strtolower($b->name) => $b->id])->all();
            $categoryIds = Category::where('store_id', $storeId)->get(['id', 'name'])
                ->mapWithKeys(fn ($c) => [mb_strtolower($c->name) => $c->id])->all();

            // Valid GST rate ids (small table). Unknown ids are left blank instead of failing the row.
            $validGstRateIds = DB::table('gst_rates')->pluck('id')
                ->mapWithKeys(fn ($id) => [(string) $id => true])->all();

            $seenSkus = [];
            $seenBarcodes = [];
            $created = 0;
            $errors = [];

            foreach ($items as $item) {
                $rowNumber = $item['row'];
                $data = $item['data'];

                // GST rate not available / not found -> save the product with a blank GST rate.
                if ($data['gst_rate_id'] !== null && ! isset($validGstRateIds[$data['gst_rate_id']])) {
                    $data['gst_rate_id'] = null;
                }

                $validator = Validator::make($data, [
                    'name' => 'required|string|max:255',
                    'sku' => 'nullable|string|max:255',
                    'barcode' => 'nullable|string|max:255',
                    'brand' => 'nullable|string|max:255',
                    'category' => 'nullable|string|max:255',
                    'hsn_code' => 'nullable|string|max:255',
                    'mrp' => 'nullable|numeric|min:0',
                    'selling_price' => 'nullable|numeric|min:0',
                    'cost_price' => 'nullable|numeric|min:0',
                    'gst_inclusive' => 'nullable|in:0,1',
                    'is_price_override' => 'nullable|in:0,1',
                ]);

                if ($validator->fails()) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'name' => $data['name'] ?: null,
                        'errors' => $validator->errors()->all(),
                    ];

                    continue;
                }

                $skuKey = mb_strtolower($data['sku']);
                $barcodeKey = mb_strtolower($data['barcode']);

                // SKU / barcode are globally unique in the database.
                if ($data['sku'] !== '') {
                    if (isset($existingSkus[$skuKey])) {
                        $errors[] = ['row' => $rowNumber, 'name' => $data['name'], 'errors' => ['SKU already exists: '.$data['sku']]];

                        continue;
                    }
                    if (isset($seenSkus[$skuKey])) {
                        $errors[] = ['row' => $rowNumber, 'name' => $data['name'], 'errors' => ['Duplicate SKU in this file: '.$data['sku']]];

                        continue;
                    }
                }

                if ($data['barcode'] !== '') {
                    if (isset($existingBarcodes[$barcodeKey])) {
                        $errors[] = ['row' => $rowNumber, 'name' => $data['name'], 'errors' => ['Barcode already exists: '.$data['barcode']]];

                        continue;
                    }
                    if (isset($seenBarcodes[$barcodeKey])) {
                        $errors[] = ['row' => $rowNumber, 'name' => $data['name'], 'errors' => ['Duplicate barcode in this file: '.$data['barcode']]];

                        continue;
                    }
                }

                try {
                    $brandId = $this->importLookupId(Brand::class, $brandIds, $data['brand'], $storeId, $user->id);
                    $categoryId = $this->importLookupId(Category::class, $categoryIds, $data['category'], $storeId, $user->id);

                    $barcodeToSave = $data['barcode'];

                    if ($barcodeToSave === '') {
                        do {
                            $barcodeToSave = $this->generateEan13Barcode();
                        } while (
                            isset($seenBarcodes[mb_strtolower($barcodeToSave)])
                            || DB::table('products')->where('barcode', $barcodeToSave)->exists()
                        );
                    }

                    Product::create([
                        'store_id' => $storeId,
                        'name' => $data['name'],
                        'sku' => $data['sku'] !== '' ? $data['sku'] : null,
                        'barcode' => $barcodeToSave,
                        'plu_code' => null,
                        'brand_id' => $brandId,
                        'category_id' => $categoryId,
                        'hsn_code' => $data['hsn_code'] !== '' ? $data['hsn_code'] : null,
                        'gst_rate_id' => $data['gst_rate_id'],
                        'mrp' => $data['mrp'],
                        'selling_price' => $data['selling_price'],
                        'cost_price' => $data['cost_price'],
                        'gst_inclusive' => $data['gst_inclusive'] ?? 0,
                        'is_price_override' => $data['is_price_override'] ?? 0,
                        'created_by' => $user->id,
                    ]);

                    if ($data['sku'] !== '') {
                        $seenSkus[$skuKey] = true;
                    }
                    $seenBarcodes[mb_strtolower($barcodeToSave)] = true;

                    $created++;
                } catch (\Throwable $e) {
                    report($e);

                    $errors[] = [
                        'row' => $rowNumber,
                        'name' => $data['name'],
                        'errors' => ['Could not save this product. Check its database constraints.'],
                    ];
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Product import completed.',
                'summary' => [
                    'total_rows' => count($items),
                    'created' => $created,
                    'skipped' => count($errors),
                ],
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            report($e);
            dd(
                get_class($e),
                $e->getMessage()
            );

            return response()->json([
                'status' => false,
                'message' => 'Excel import failed. Check the file format and server logs.',
            ], 422);
        }
    }

    /**
     * Excel gives numbers as int/float (barcode 8901234567890 -> float), which would
     * fail the "string" validation rules. Convert every cell to clean text / numbers first.
     */
    private function normalizeImportRow(array $raw): array
    {
        return [
            'name' => $this->importText($raw['name'] ?? null),
            'sku' => $this->importText($raw['sku'] ?? null),
            'barcode' => $this->importText($raw['barcode'] ?? null),
            'brand' => $this->importText($raw['brand'] ?? null),
            'category' => $this->importText($raw['category'] ?? null),
            'hsn_code' => $this->importText($raw['hsn_code'] ?? null),
            'gst_rate_id' => $this->importNumber($raw['gst_rate_id'] ?? null),
            'mrp' => $this->importNumber($raw['mrp'] ?? null),
            'selling_price' => $this->importNumber($raw['selling_price'] ?? null),
            'cost_price' => $this->importNumber($raw['cost_price'] ?? null),
            'gst_inclusive' => $this->importFlag($raw['gst_inclusive'] ?? null),
            'is_price_override' => $this->importFlag($raw['is_price_override'] ?? null),
        ];
    }

    private function importText($value): string
    {
        if ($value === null) {
            return '';
        }

        // 8901234567890.0 -> "8901234567890" (no ".0" / scientific notation)
        if (is_float($value) && floor($value) == $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }

    private function importNumber($value): ?string
    {
        $text = str_replace([',', '₹', ' '], '', $this->importText($value));

        return $text === '' ? null : $text;
    }

    /** Accepts 1/0, yes/no, y/n, true/false. Anything else is left as-is so validation rejects it. */
    private function importFlag($value): ?string
    {
        $text = mb_strtolower($this->importText($value));

        if ($text === '') {
            return null;
        }
        if (in_array($text, ['1', 'yes', 'y', 'true'], true)) {
            return '1';
        }
        if (in_array($text, ['0', 'no', 'n', 'false'], true)) {
            return '0';
        }

        return $text;
    }

    /** Which of these SKUs/barcodes already exist in the products table? (lowercased lookup map) */
    private function existingImportValues(string $column, array $values): array
    {
        $values = array_values(array_unique(array_filter($values, fn ($v) => $v !== '' && $v !== null)));
        $found = [];

        foreach (array_chunk($values, 500) as $chunk) {
            foreach (DB::table('products')->whereIn($column, $chunk)->pluck($column) as $existing) {
                $found[mb_strtolower((string) $existing)] = true;
            }
        }

        return $found;
    }

    /** Find (or create once) a brand/category by name for this store. */
    private function importLookupId(string $model, array &$cache, string $name, $storeId, $userId): ?int
    {
        if ($name === '') {
            return null;
        }

        $key = mb_strtolower($name);

        if (! isset($cache[$key])) {
            $cache[$key] = $model::create([
                'store_id' => $storeId,
                'name' => $name,
                'created_by' => $userId,
            ])->id;
        }

        return (int) $cache[$key];
    }
}
