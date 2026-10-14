<?php

namespace App\Http\Controllers\Api;

use App\Exports\SupplierTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\SupplierRowsImport;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\PurchaseBill;
use App\Models\PurchasePayment;
use App\Models\PurchaseLine;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;


class SupplierController extends Controller
{
    private const IMPORT_MAX_ROWS = 2000;

    private const GSTIN_REGEX = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/';

    /** First two digits of a GSTIN = state code. Names match the state dropdown in the supplier form. */
    private const GST_STATE_CODES = [
        '01' => 'Jammu and Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '25' => 'Dadra and Nagar Haveli and Daman and Diu',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '27' => 'Maharashtra',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
    ];

    // public function index()
    // {
    //     $storeId = Auth::user()->store_id;
    //     $suppliers = Supplier::where('store_id', $storeId)->with(['store'])->get();

    //     return response()->json([
    //         'status' => true,
    //         'suppliers' => $suppliers
    //     ], 200);
    // }

    public function index()
    {
        $user = Auth::user();

        if (!in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $branchIds = null;

        if ($user->role === 'manager') {
            $branchIds = $user->branches()->pluck('branches.id')->toArray();
        }

        $suppliers = Supplier::where('store_id', $user->store_id)
            ->with('store')
            ->get()
            ->map(function ($supplier) use ($user, $branchIds) {
                $billsQuery = PurchaseBill::where('store_id', $user->store_id)
                    ->where('supplier_id', $supplier->id);

                if ($branchIds !== null) {
                    $billsQuery->whereIn('branch_id', $branchIds);
                }

                $bills = $billsQuery->get(['id', 'total_amount']);

                $billIds = $bills->pluck('id');

                $totalPurchase = (float) $bills->sum('total_amount');

                $totalPaid = $billIds->isEmpty()
                    ? 0
                    : (float) PurchasePayment::whereIn('purchase_bill_id', $billIds)
                        ->where('status', 'success')
                        ->sum('amount');

                $supplier->total_purchase = round($totalPurchase, 2);
                $supplier->total_paid = round($totalPaid, 2);
                $supplier->outstanding_due = round(
                    max(0, $totalPurchase - $totalPaid),
                    2
                );
                $supplier->purchase_bill_count = $bills->count();

                return $supplier;
            });

        return response()->json([
            'status' => true,
            'suppliers' => $suppliers,
        ], 200);
    }


    public function show($id)
    {
        $storeId = Auth::user()->store_id;
        $supplier = Supplier::where('store_id', $storeId)->where('id', $id)->first();

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'Supplier not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'supplier' => $supplier
        ], 200);
    }
    public function purchaseHistory($id)
    {
        $user = Auth::user();

        if (!in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $supplier = Supplier::where('store_id', $user->store_id)
            ->where('id', $id)
            ->first();

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'Supplier not found',
            ], 404);
        }

        $query = PurchaseBill::with([
            'branch:id,name',
            'payments' => fn($q) => $q->where('status', 'success'),
            'lines.product:id,name,sku',
        ])
            ->where('store_id', $user->store_id)
            ->where('supplier_id', $supplier->id);

        if ($user->role === 'manager') {
            $branchIds = $user->branches()->pluck('branches.id')->toArray();
            $query->whereIn('branch_id', $branchIds);
        }

        $bills = $query->orderByDesc('bill_date')->get();

        $history = $bills->map(function ($bill) {
            $paid = (float) $bill->payments->sum('amount');
            $total = (float) $bill->total_amount;

            return [
                'id' => $bill->id,
                'bill_no' => $bill->bill_no,
                'bill_date' => $bill->bill_date,
                'branch' => $bill->branch?->name,
                'total_amount' => round($total, 2),
                'paid_amount' => round($paid, 2),
                'due_amount' => round(max(0, $total - $paid), 2),
                'products' => $bill->lines->map(fn($line) => [
                    'product_id' => $line->product_id,
                    'product_name' => $line->product?->name ?? 'Deleted product',
                    'qty' => $line->qty,
                    'purchase_rate' => $line->purchase_rate,
                ])->values(),
            ];
        });

        return response()->json([
            'status' => true,
            'supplier' => $supplier,
            'purchase_history' => $history,
        ]);
    }

public function rateComparison(Request $request)
{
    $user = Auth::user();

    if (!in_array($user->role, ['admin', 'manager'], true)) {
        return response()->json([
            'status' => false,
            'message' => 'Unauthorized',
        ], 403);
    }

    $validated = $request->validate([
        'product_id' => 'nullable|integer',
        'supplier_id' => 'nullable|integer',
        'date_from' => 'nullable|date',
        'date_to' => 'nullable|date|after_or_equal:date_from',
    ]);

    $query = PurchaseLine::query()
        ->join('purchase_bills', 'purchase_lines.purchase_bill_id', '=', 'purchase_bills.id')
        ->join('products', 'purchase_lines.product_id', '=', 'products.id')
        ->join('suppliers', 'purchase_bills.supplier_id', '=', 'suppliers.id')
        ->where('purchase_bills.store_id', $user->store_id)
        ->whereNull('purchase_bills.deleted_at')
        ->where('suppliers.store_id', $user->store_id)
        ->select([
            'purchase_lines.product_id',
            'products.name as product_name',
            'purchase_bills.supplier_id',
            'suppliers.name as supplier_name',
            'purchase_lines.purchase_rate',
            'purchase_lines.qty',
            'purchase_bills.bill_no',
            'purchase_bills.bill_date',
            'purchase_bills.branch_id',
        ]);

    if ($user->role === 'manager') {
        $branchIds = $user->branches()
            ->pluck('branches.id')
            ->toArray();

        $query->whereIn('purchase_bills.branch_id', $branchIds);
    }

    if (!empty($validated['product_id'])) {
        $query->where(
            'purchase_lines.product_id',
            $validated['product_id']
        );
    }

    if (!empty($validated['supplier_id'])) {
        $query->where(
            'purchase_bills.supplier_id',
            $validated['supplier_id']
        );
    }

    if (!empty($validated['date_from'])) {
        $query->whereDate(
            'purchase_bills.bill_date',
            '>=',
            $validated['date_from']
        );
    }

    if (!empty($validated['date_to'])) {
        $query->whereDate(
            'purchase_bills.bill_date',
            '<=',
            $validated['date_to']
        );
    }

    $lines = $query
        ->orderByDesc('purchase_bills.bill_date')
        ->orderByDesc('purchase_bills.id')
        ->get();

    $products = $lines
        ->groupBy('product_id')
        ->map(function ($productLines, $productId) {
            $supplierRates = $productLines
                ->groupBy('supplier_id')
                ->map(function ($supplierLines, $supplierId) {
                    $latest = $supplierLines->first();
                    $rates = $supplierLines
                        ->pluck('purchase_rate')
                        ->map(fn ($rate) => (float) $rate);

                    return [
                        'supplier_id' => (int) $supplierId,
                        'supplier_name' => $latest->supplier_name,
                        'latest_rate' => round(
                            (float) $latest->purchase_rate,
                            2
                        ),
                        'lowest_rate' => round($rates->min(), 2),
                        'highest_rate' => round($rates->max(), 2),
                        'average_rate' => round($rates->avg(), 2),
                        'last_purchase_date' => $latest->bill_date,
                        'last_bill_no' => $latest->bill_no,
                        'purchase_count' => $supplierLines->count(),
                    ];
                })
                ->sortBy('latest_rate')
                ->values();

            return [
                'product_id' => (int) $productId,
                'product_name' => $productLines->first()->product_name,
                'lowest_current_supplier' => $supplierRates->first(),
                'supplier_rates' => $supplierRates,
            ];
        })
        ->values();

    return response()->json([
        'status' => true,
        'filters' => $validated,
        'products' => $products,
    ]);
}

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                // 'gstin' => 'required|string|max:15',
                // 'contact' => 'required|string|max:20',
                // 'address' => 'required|string',
                'state' => 'required|string|max:20'
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $supplier = Supplier::create([
                'store_id' => $storeId,
                'name' => $request->name,
                'gstin' => $request->gstin,
                'contact' => $request->contact,
                'address' => $request->address,
                'state' => $request->state,
                'created_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Supplier created successfully',
                'supplier' => $supplier
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the supplier.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                // 'gstin' => 'required|string|max:15',
                // 'contact' => 'required|string|max:20',
                // 'address' => 'required|string',
                'state' => 'required|string|max:20',
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $supplier = Supplier::where('store_id', $storeId)->where('id', $id)->first();

            if (!$supplier) {
                return response()->json([
                    'status' => false,
                    'message' => 'Supplier not found'
                ], 404);
            }

            $supplier->update([
                'name' => $request->name,
                'gstin' => $request->gstin,
                'contact' => $request->contact,
                'address' => $request->address,
                'state' => $request->state,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Supplier updated successfully',
                'supplier' => $supplier
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating the supplier.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $supplier = Supplier::where('store_id', $storeId)->where('id', $id)->first();

            if (!$supplier) {
                return response()->json([
                    'status' => false,
                    'message' => 'Supplier not found'
                ], 404);
            }
            $hasPurchaseHistory = PurchaseBill::where('store_id', $storeId)
                ->where('supplier_id', $supplier->id)
                ->exists();

            if ($hasPurchaseHistory) {
                return response()->json([
                    'status' => false,
                    'message' => 'Supplier cannot be deleted because purchase history exists.',
                ], 409);
            }
            $supplier->delete();

            return response()->json([
                'status' => true,
                'message' => 'Supplier deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting the supplier.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /* ───────────── Excel import ───────────── */

    /**
     * Empty Excel template (headings only) so a new user knows the exact columns.
     */
    public function downloadImportTemplate()
    {
        $user = Auth::user();

        if (!$user || !in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        return Excel::download(
            new SupplierTemplateExport(),
            'supplier-import-template.xlsx'
        );
    }

    public function importExcel(Request $request)
    {
        $user = Auth::user();

        if (!$user || !in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
        ]);

        set_time_limit(120);

        try {
            $storeId = $user->store_id;

            $sheetRows = Excel::toCollection(
                new SupplierRowsImport(),
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

                $items[] = ['row' => $index + 2, 'raw' => $raw];
            }

            if (empty($items)) {
                return response()->json([
                    'status' => false,
                    'message' => 'The file has no supplier rows.',
                ], 422);
            }

            if (!array_key_exists('name', $items[0]['raw'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Column "name" was not found. Please download the template and keep its headings unchanged.',
                ], 422);
            }

            if (count($items) > self::IMPORT_MAX_ROWS) {
                return response()->json([
                    'status' => false,
                    'message' => 'Too many rows. Please import at most ' . self::IMPORT_MAX_ROWS . ' suppliers at a time.',
                ], 422);
            }

            // Suppliers already in this store (to detect duplicates).
            $existingGstins = [];
            $existingNames = [];
            foreach (Supplier::where('store_id', $storeId)->get(['name', 'gstin']) as $existing) {
                if (trim((string) $existing->gstin) !== '') {
                    $existingGstins[strtoupper(trim($existing->gstin))] = true;
                }
                $existingNames[mb_strtolower(trim((string) $existing->name))] = true;
            }

            $stateLookup = [];
            foreach (array_unique(array_values(self::GST_STATE_CODES)) as $stateName) {
                $stateLookup[mb_strtolower($stateName)] = $stateName;
            }

            $seenGstins = [];
            $seenNames = [];
            $created = 0;
            $errors = [];

            foreach ($items as $item) {
                $rowNumber = $item['row'];
                $data = $this->normalizeSupplierRow($item['raw']);
                $rowErrors = [];

                if ($data['name'] === '') {
                    $rowErrors[] = 'Name is required.';
                } elseif (mb_strlen($data['name']) > 255) {
                    $rowErrors[] = 'Name cannot exceed 255 characters.';
                }

                $gstinValid = $data['gstin'] !== '' && preg_match(self::GSTIN_REGEX, $data['gstin']) === 1;

                if ($data['gstin'] !== '' && !$gstinValid) {
                    $rowErrors[] = 'Invalid GSTIN format: ' . $data['gstin'];
                }

                if ($data['contact'] !== '' && preg_match('/^[0-9]{10}$/', $data['contact']) !== 1) {
                    $rowErrors[] = 'Contact must be a 10 digit mobile number.';
                }

                if (mb_strlen($data['address']) > 255) {
                    $rowErrors[] = 'Address cannot exceed 255 characters.';
                }

                // State: use the given one, otherwise take it from a valid GSTIN.
                $state = '';
                if ($data['state'] !== '') {
                    $state = $stateLookup[mb_strtolower($data['state'])] ?? '';
                    if ($state === '') {
                        $rowErrors[] = 'Unknown state: ' . $data['state'];
                    }
                } elseif ($gstinValid) {
                    $state = self::GST_STATE_CODES[substr($data['gstin'], 0, 2)] ?? '';
                }

                if ($state === '' && $data['state'] === '') {
                    $rowErrors[] = 'State is required (or give a valid GSTIN so the state can be taken from it).';
                }

                // Duplicates: by GSTIN when given, otherwise by name.
                if (empty($rowErrors)) {
                    $nameKey = mb_strtolower($data['name']);

                    if ($data['gstin'] !== '') {
                        if (isset($existingGstins[$data['gstin']])) {
                            $rowErrors[] = 'A supplier with this GSTIN already exists: ' . $data['gstin'];
                        } elseif (isset($seenGstins[$data['gstin']])) {
                            $rowErrors[] = 'Duplicate GSTIN in this file: ' . $data['gstin'];
                        }
                    } else {
                        if (isset($existingNames[$nameKey])) {
                            $rowErrors[] = 'A supplier with this name already exists: ' . $data['name'];
                        } elseif (isset($seenNames[$nameKey])) {
                            $rowErrors[] = 'Duplicate supplier name in this file: ' . $data['name'];
                        }
                    }
                }

                if (!empty($rowErrors)) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'name' => $data['name'] !== '' ? $data['name'] : null,
                        'errors' => $rowErrors,
                    ];
                    continue;
                }

                try {
                    Supplier::create([
                        'store_id' => $storeId,
                        'name' => $data['name'],
                        'gstin' => $data['gstin'] !== '' ? $data['gstin'] : null,
                        'contact' => $data['contact'] !== '' ? $data['contact'] : null,
                        'address' => $data['address'] !== '' ? $data['address'] : null,
                        'state' => $state,
                        'created_by' => $user->id,
                    ]);

                    if ($data['gstin'] !== '') {
                        $seenGstins[$data['gstin']] = true;
                    }
                    $seenNames[mb_strtolower($data['name'])] = true;

                    $created++;
                } catch (\Throwable $e) {
                    report($e);

                    $errors[] = [
                        'row' => $rowNumber,
                        'name' => $data['name'],
                        'errors' => ['Could not save this supplier. Check its database constraints.'],
                    ];
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Supplier import completed.',
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

    /**
     * Excel gives numbers as int/float (a mobile number is a float), so every cell
     * is converted to clean text first.
     */
    private function normalizeSupplierRow(array $raw): array
    {
        $contact = preg_replace('/\D+/', '', $this->importText($raw['contact'] ?? null));

        // +91 98765 43210 -> 9876543210, 098765 43210 -> 9876543210
        if (strlen($contact) === 12 && substr($contact, 0, 2) === '91') {
            $contact = substr($contact, 2);
        } elseif (strlen($contact) === 11 && $contact[0] === '0') {
            $contact = substr($contact, 1);
        }

        return [
            'name' => $this->importText($raw['name'] ?? null),
            'gstin' => strtoupper(preg_replace('/\s+/', '', $this->importText($raw['gstin'] ?? null))),
            'contact' => $contact,
            'address' => $this->importText($raw['address'] ?? null),
            'state' => $this->importText($raw['state'] ?? null),
        ];
    }

    private function importText($value): string
    {
        if ($value === null) {
            return '';
        }

        // 9876543210.0 -> "9876543210" (no ".0" / scientific notation)
        if (is_float($value) && floor($value) == $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }
}