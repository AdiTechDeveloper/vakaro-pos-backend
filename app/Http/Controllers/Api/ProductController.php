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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;
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
                $term = '%'.$request->search.'%';
                $q->where(function ($q2) use ($term) {
                    $q2->where('name', 'like', $term)
                        ->orWhere('salt_composition', 'like', $term)
                        ->orWhere('manufacturer', 'like', $term);
                });
            })
            ->with(['store', 'brand', 'category', 'gstRate'])
            ->with(['inventories' => function ($q) use ($branchIds) {
     $q->whereIn('branch_id', $branchIds)
        ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
        ->orderBy('id', 'asc');
}])
            // ->with(['inventories' => function ($q) use ($branchIds) {
            //     $q->whereIn('branch_id', $branchIds)
            //         ->whereRaw(
            //             '(qty - sold_qty - COALESCE(expired_qty, 0)) > 0'
            //         )
            //         ->where(function ($q2) {
            //             $q2->whereNull('expiry_date')
            //                 ->orWhereDate(
            //                     'expiry_date',
            //                     '>=',
            //                     now()->toDateString()
            //                 );
            //         })
            //         ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            //         ->orderBy('id', 'asc');
            // }])
            ->addSelect([
                'total_stock' => Inventory::selectRaw(
                    'COALESCE(SUM(qty - sold_qty - COALESCE(expired_qty, 0)), 0)'
                )
                    ->whereColumn('inventories.product_id', 'products.id')
                    ->whereIn('inventories.branch_id', $branchIds)
                    ->whereRaw(
                        '(qty - sold_qty - COALESCE(expired_qty, 0)) > 0'
                    )
                    ->where(function ($q) {
                        $q->whereNull('inventories.expiry_date')
                            ->orWhereDate(
                                'inventories.expiry_date',
                                '>=',
                                now()->toDateString()
                            );
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

            // Same batch + same selling price = one selectable POS option.
            $groupedBatches = $product->inventories->groupBy(
                function ($inv) {
                    return $inv->batch_no.'|'
                        .number_format((float) $inv->selling_price, 2, '.', '')
                        .'|'
                        .($inv->expiry_date ? substr((string) $inv->expiry_date, 0, 10) : 'none');
                }
            );

            $batches = $groupedBatches->map(function ($invGroup) {

                // Ignore records without sellable stock.
                // $eligibleRows = $invGroup->filter(function ($inv) {
                //     $available = (float) $inv->qty
                //         - (float) $inv->sold_qty
                //         - (float) ($inv->expired_qty ?? 0);

                //     return $available > 0;
                // });

                $eligibleRows = $invGroup->filter(function ($inv) {
    $available = (float) $inv->qty
        - (float) $inv->sold_qty
        - (float) ($inv->expired_qty ?? 0);

    return $available > 0
        && (
            !$inv->expiry_date
            || \Carbon\Carbon::parse($inv->expiry_date)->startOfDay()
                ->gte(now()->startOfDay())
        );
});

                if ($eligibleRows->isEmpty()) {
                    return null;
                }

                // This ID must identify a real, sellable inventory row.
                $first = $eligibleRows->first();

                $totalQty = $eligibleRows->sum(
                    fn ($inv) => (float) $inv->qty
                );

                $totalSoldQty = $eligibleRows->sum(
                    fn ($inv) => (float) $inv->sold_qty
                );

                $totalExpiredQty = $eligibleRows->sum(
                    fn ($inv) => (float) ($inv->expired_qty ?? 0)
                );

                $totalFree = $eligibleRows->sum(
                    fn ($inv) => (float) $inv->free
                );

                $availableQty = $eligibleRows->sum(
                    fn ($inv) => max(
                        0,
                        (float) $inv->qty
                            - (float) $inv->sold_qty
                            - (float) ($inv->expired_qty ?? 0)
                    )
                );

                return [
                    // Use this ID when adding the option to the cart.
                    'id' => $first->id,

                    'product_id' => $first->product_id,
                    'branch_id' => $first->branch_id,
                    'batch_no' => $first->batch_no,

                    'mrp' => (float) $first->mrp,
                    'cost_price' => (float) $first->cost_price,
                    'selling_price' => (float) $first->selling_price,

                    'qty' => $totalQty,
                    'sold_qty' => $totalSoldQty,
                    'expired_qty' => $totalExpiredQty,
                    'free' => $totalFree,
                    'qty_available' => $availableQty,

                    'expiry_date' => $first->expiry_date,
                    'is_opening' => $first->is_opening,

                    // Preserve barcode-level details.
                    'batch_barcode' => $first->batch_barcode,
                    'inventory_ids' => $eligibleRows->pluck('id')->values(),
                    'batch_barcodes' => $eligibleRows
                        ->pluck('batch_barcode')
                        ->unique()
                        ->values(),
                ];
            })
                ->filter()
                ->values();

            $prices = $batches
                ->pluck('selling_price')
                ->filter(fn ($price) => $price !== null);

            // $data = $product->toArray();

            // $data['min_price'] = $prices->min();
            // $data['max_price'] = $prices->max();

            // $data['has_multiple_prices'] =
            //     $prices->unique()->count() > 1;

            // $data['batch_count'] = $batches->count();
            // $data['batches'] = $batches;

            // unset($data['inventories']);

            // return $data;

            $data = $product->toArray();

            // Use calculated sellable inventory stock instead of stale products.stock
            $calculatedStock = (float) ($product->total_stock ?? 0);

            $data['stock'] = $calculatedStock;
            $data['total_stock'] = $calculatedStock;

            $data['min_price'] = $prices->min();
            $data['max_price'] = $prices->max();

            $data['has_multiple_prices'] =
                $prices->unique()->count() > 1;

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

                // Pharmacy columns
                'salt_composition' => 'nullable|string|max:255',
                'manufacturer' => 'nullable|string|max:255',
                'drug_schedule' => 'nullable|in:none,G,H,H1,X',
                'requires_prescription' => 'nullable|boolean',
                'pack_type' => 'nullable|string|max:30',
                'units_per_pack' => 'nullable|integer|min:1',
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

            $pharmacy = $request->only([
                'salt_composition', 'manufacturer', 'drug_schedule',
                'requires_prescription', 'pack_type', 'units_per_pack',
            ]);

            $pharmacy['drug_schedule'] = $pharmacy['drug_schedule'] ?? 'none';
            // if H, H1, X then prescription always required
            if (in_array($pharmacy['drug_schedule'], ['H', 'H1', 'X'], true)) {
                $pharmacy['requires_prescription'] = true;
            }

            $product = Product::create(array_merge([
                'store_id' => $storeId,
                'sku' => $request->sku,
                'barcode' => $barcode, // Master barcode
                'name' => $request->name,
                'brand_id' => $request->brand_id,
                'category_id' => $request->category_id,
                'manufacturer' => Brand::where('id', $request->brand_id)->value('name'),
                'hsn_code' => $request->hsn_code,
                'gst_rate_id' => $request->gst_rate_id,
                'gst_inclusive' => $request->gst_inclusive ?? 0,
                'is_price_override' => $request->input('is_price_override', 0),
                'sale_type' => $request->input('sale_type', 'unit'),
                'plu_code' => $request->plu_code,
                'price_per_kg' => $request->price_per_kg,
                'created_by' => Auth::id(),
            ], $pharmacy));

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

                // Pharmacy columns
                'salt_composition' => 'nullable|string|max:255',
                'manufacturer' => 'nullable|string|max:255',
                'drug_schedule' => 'nullable|in:none,G,H,H1,X',
                'requires_prescription' => 'nullable|boolean',
                'pack_type' => 'nullable|string|max:30',
                'units_per_pack' => 'nullable|integer|min:1',
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
                'manufacturer' => $request->has('brand_id')
                    ? Brand::where('id', $request->brand_id)->value('name')
                    : $product->manufacturer,
                'category_id' => $request->category_id,
                'hsn_code' => $request->hsn_code,
                'gst_rate_id' => $request->gst_rate_id,
                'gst_inclusive' => $request->gst_inclusive ?? 0,
                'is_price_override' => $request->has('is_price_override')
                    ? (int) $request->input('is_price_override')
                    : $product->is_price_override,
                'sale_type' => $request->input('sale_type', $product->sale_type),
                'plu_code' => $request->has('plu_code') ? $request->plu_code : $product->plu_code,
                'price_per_kg' => $request->has('price_per_kg') ? $request->price_per_kg : $product->price_per_kg,
                'updated_by' => Auth::id(),
            ]);

            $pharmacy = $request->only([
                'salt_composition', 'manufacturer', 'drug_schedule',
                'requires_prescription', 'pack_type', 'units_per_pack',
            ]);
            if (array_key_exists('drug_schedule', $pharmacy) && in_array($pharmacy['drug_schedule'], ['H', 'H1', 'X'], true)) {
                $pharmacy['requires_prescription'] = true;
            }
            if (! empty($pharmacy)) {
                $product->update($pharmacy);
            }

            return response()->json([
                'status' => true,
                'message' => 'Product updated successfully',
                'product' => $product,
            ], 200);
        } catch (\Exception $e) {

            $storeId = Auth::user()->store_id;

            $product = Product::where('store_id', $storeId)->where('id', $id)->first();

            if (! $product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            $barcodeChanged = $request->filled('barcode')
                && $request->barcode !== $product->barcode;

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

            if ($barcodeChanged) {
                $product->inventories()->update(['batch_barcode' => $request->barcode]);
            }

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

    // POST /api/products/{id}/image   (multipart: image)
    public function uploadImage(Request $request, $id)
    {
        $user = Auth::user();

        if (! in_array($user->role, ['admin', 'manager'])) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $product = Product::where('store_id', $user->store_id)->where('id', $id)->first();

        if (! $product) {
            return response()->json(['status' => false, 'message' => 'Product not found'], 404);
        }

        // SVG jaan-boojhkar allowed nahi (script chhupa sakta hai)
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        $old = $product->image;
        $path = $request->file('image')->store("products/{$user->store_id}", 'public');

        $product->update(['image' => $path, 'updated_by' => $user->id]);

        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return response()->json([
            'status' => true,
            'message' => 'Product image updated',
            'image_url' => $product->image_url,
            'product' => $product,
        ], 200);
    }

    // DELETE /api/products/{id}/image
    public function deleteImage($id)
    {
        $user = Auth::user();

        if (! in_array($user->role, ['admin', 'manager'])) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $product = Product::where('store_id', $user->store_id)->where('id', $id)->first();

        if (! $product) {
            return response()->json(['status' => false, 'message' => 'Product not found'], 404);
        }

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
            $product->update(['image' => null, 'updated_by' => $user->id]);
        }

        return response()->json(['status' => true, 'message' => 'Product image removed'], 200);
    }

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

        $query->whereDate('expiry_date', '<', now()->toDateString());

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

            // cost_price is saved only if the products table really has that column
            $hasCostPrice = \Illuminate\Support\Facades\Schema::hasColumn('products', 'cost_price');

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
                    'cost_price' => 'nullable|numeric|min:0',
                    'gst_inclusive' => 'nullable|in:0,1',
                    'is_price_override' => 'nullable|in:0,1',

                    'sale_type' => 'required|in:unit,weight',
                    'plu_code' => 'nullable|string|max:10',
                    'price_per_kg' => 'nullable|numeric|gt:0',

                ]);

                if ($data['sale_type'] === 'weight') {
                    $extraValidator = Validator::make($data, [
                        'plu_code' => 'required|string|max:10',
                        'price_per_kg' => 'required|numeric|gt:0',
                    ]);

                    if ($extraValidator->fails()) {
                        $errors[] = [
                            'row' => $rowNumber,
                            'name' => $data['name'] ?: null,
                            'errors' => $extraValidator->errors()->all(),
                        ];

                        continue;
                    }
                }

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

                    $attributes = [
                        'store_id' => $storeId,
                        'name' => $data['name'],
                        'sku' => $data['sku'] !== '' ? $data['sku'] : null,
                        'barcode' => $barcodeToSave,
                        'brand_id' => $brandId,
                        'category_id' => $categoryId,
                        'hsn_code' => $data['hsn_code'] !== '' ? $data['hsn_code'] : null,
                        'gst_rate_id' => $data['gst_rate_id'],
                        'gst_inclusive' => $data['gst_inclusive'] ?? 0,
                        'is_price_override' => $data['is_price_override'] ?? 0,
                        'created_by' => $user->id,
                        'sale_type' => $data['sale_type'],
                        'plu_code' => $data['plu_code'] !== ''
                            ? $data['plu_code']
                            : null,
                        'price_per_kg' => $data['sale_type'] === 'weight'
                            ? $data['price_per_kg']
                            : null,
                    ];

                    if ($hasCostPrice) {
                        $attributes['cost_price'] = $data['cost_price'];
                    }

                    Product::create($attributes);

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
                        'errors' => [config('app.debug') ? $e->getMessage() : 'Could not save this product. Check its database constraints.'],
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

            return response()->json([
                'status' => false,
                'message' => 'Excel import failed. Check the file format and server logs.',
            ], 422);
        }
    }

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
            'cost_price' => $this->importNumber($raw['cost_price'] ?? null),
            'gst_inclusive' => $this->importFlag($raw['gst_inclusive'] ?? null),
            'is_price_override' => $this->importFlag($raw['is_price_override'] ?? null),

            'sale_type' => strtolower(
                $this->importText($raw['sale_type'] ?? 'unit')
            ),
            'plu_code' => $this->importText($raw['plu_code'] ?? null),
            'price_per_kg' => $this->importNumber($raw['price_per_kg'] ?? null),
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
public function bulkUploadImages(Request $request)
{
    $validator = Validator::make($request->all(), [
        'file' => 'required|file|mimes:zip|max:51200',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()->first(),
        ], 422);
    }

    $user = Auth::user();

    if (!$user || !in_array($user->role, ['admin', 'manager'])) {
        return response()->json([
            'status' => false,
            'message' => 'Unauthorized access.',
        ], 403);
    }

    if (!class_exists(\ZipArchive::class)) {
        return response()->json([
            'status' => false,
            'message' => 'PHP ZIP extension is not enabled.',
        ], 500);
    }

    $zip = new \ZipArchive();

    $openResult = $zip->open($request->file('file')->getRealPath());

    if ($openResult !== true) {
        return response()->json([
            'status' => false,
            'message' => 'Unable to open ZIP file.',
        ], 422);
    }

    $totalFiles = 0;
    $updated = 0;
    $notFound = 0;
    $failed = 0;

    $results = [];
    $processedSkus = [];

    $storeId = $user->store_id;

    try {
        // Maximum 1000 ZIP entries
        if ($zip->numFiles > 1000) {
            return response()->json([
                'status' => false,
                'message' => 'ZIP file contains too many entries. Maximum allowed is 1000.',
            ], 422);
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {

            $entryName = $zip->getNameIndex($i);

            if (!$entryName) {
                continue;
            }

            // Normalize Windows and Linux paths
            $entryName = str_replace('\\', '/', $entryName);

            // Skip directories
            if (str_ends_with($entryName, '/')) {
                continue;
            }

            // Get filename from root or nested folder
            $filename = basename($entryName);

            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // Only supported image formats
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
                continue;
            }

            $totalFiles++;

            // Maximum 5 MB per image
            $fileStat = $zip->statIndex($i);
            $fileSize = $fileStat['size'] ?? 0;

            if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
                $failed++;

                $results[] = [
                    'file' => $filename,
                    'status' => 'failed',
                    'reason' => 'Invalid image size. Maximum allowed is 5 MB.',
                ];

                continue;
            }

            // SKU is filename without extension
            $sku = trim(pathinfo($filename, PATHINFO_FILENAME));

            if ($sku === '') {
                $failed++;

                $results[] = [
                    'file' => $filename,
                    'status' => 'failed',
                    'reason' => 'SKU is missing from filename.',
                ];

                continue;
            }

            // Prevent duplicate SKU entries in ZIP
            if (isset($processedSkus[$sku])) {
                $failed++;

                $results[] = [
                    'file' => $filename,
                    'sku' => $sku,
                    'status' => 'failed',
                    'reason' => 'Duplicate SKU found in ZIP.',
                ];

                continue;
            }

            $processedSkus[$sku] = true;

            // Read image contents
            $contents = $zip->getFromIndex($i);

            if ($contents === false) {
                $failed++;

                $results[] = [
                    'file' => $filename,
                    'sku' => $sku,
                    'status' => 'failed',
                    'reason' => 'Unable to read image from ZIP.',
                ];

                continue;
            }

            // Verify actual image MIME type
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($contents);

            $allowedMimes = [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
            ];

            if (!in_array($mimeType, $allowedMimes[$extension] ?? [])) {
                $failed++;

                $results[] = [
                    'file' => $filename,
                    'sku' => $sku,
                    'status' => 'failed',
                    'reason' => 'Invalid image format or file extension mismatch.',
                ];

                continue;
            }

            // Find product in current store
            $products = Product::where('store_id', $storeId)
                ->where('sku', $sku)
                ->get();

            if ($products->isEmpty()) {
                $notFound++;

                $results[] = [
                    'file' => $filename,
                    'sku' => $sku,
                    'status' => 'not_found',
                    'reason' => 'Product not found.',
                ];

                continue;
            }

            if ($products->count() > 1) {
                $failed++;

                $results[] = [
                    'file' => $filename,
                    'sku' => $sku,
                    'status' => 'failed',
                    'reason' => 'Duplicate SKU found in database.',
                ];

                continue;
            }

            $product = $products->first();

            $oldImage = $product->image;
            $newPath = null;

            try {
                $newPath = 'products/' . $storeId . '/' .
                    \Illuminate\Support\Str::uuid() . '.' . $extension;

                // Save new image
                $saved = Storage::disk('public')->put($newPath, $contents);

                if (!$saved) {
                    throw new \RuntimeException('Unable to save image.');
                }

                // Update product image
                $product->update([
                    'image' => $newPath,
                    'updated_by' => $user->id,
                ]);

                // Delete old image after successful update
                if (
                    $oldImage &&
                    $oldImage !== $newPath &&
                    Storage::disk('public')->exists($oldImage)
                ) {
                    Storage::disk('public')->delete($oldImage);
                }

                $updated++;

                $results[] = [
                    'file' => $filename,
                    'sku' => $sku,
                    'product_id' => $product->id,
                    'status' => 'updated',
                    'message' => 'Product image uploaded successfully.',
                ];

            } catch (\Throwable $e) {

                // Remove newly uploaded image if update fails
                if ($newPath && Storage::disk('public')->exists($newPath)) {
                    Storage::disk('public')->delete($newPath);
                }

                $failed++;

                \Log::error('Bulk product image upload failed', [
                    'sku' => $sku,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'file' => $filename,
                    'sku' => $sku,
                    'status' => 'failed',
                    'reason' => 'Failed to update product image.',
                ];
            }
        }

    } finally {
        $zip->close();
    }

    return response()->json([
        'status' => true,
        'message' => 'Bulk image upload completed.',
        'summary' => [
            'total_files' => $totalFiles,
            'updated' => $updated,
            'not_found' => $notFound,
            'failed' => $failed,
        ],
        'results' => $results,
    ]);
}
}