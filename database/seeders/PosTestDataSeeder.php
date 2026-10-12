<?php

namespace Database\Seeders;


use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PosTestDataSeeder extends Seeder
{

    public function run(): void
    {
        $storeId = 1;
        $now = now();

        DB::transaction(function () use ($storeId, $now) {
            $store = DB::table('stores')->where('id', $storeId)->first();

            if (! $store) {
                throw new RuntimeException("Store ID {$storeId} does not exist.");
            }

            $branches = DB::table('branches')
                ->where('store_id', $storeId)
                ->orderBy('id')
                ->pluck('id');

            $users = DB::table('users')
                ->where('store_id', $storeId)
                ->where('is_active', 1)
                ->orderBy('id')
                ->pluck('id');

            $categories = DB::table('categories')
                ->where('store_id', $storeId)
                ->orderBy('id')
                ->pluck('id');

            $brands = DB::table('brands')
                ->where('store_id', $storeId)
                ->orderBy('id')
                ->pluck('id');

            $gstRates = DB::table('gst_rates')
                ->where('store_id', $storeId)
                ->where('active', 1)
                ->orderBy('rate')
                ->get();

            if ($branches->isEmpty() || $users->isEmpty()) {
                throw new RuntimeException('Store branches or active users are missing.');
            }

            if ($categories->isEmpty() || $brands->isEmpty() || $gstRates->isEmpty()) {
                throw new RuntimeException(
                    'Categories, brands or active GST rates are missing. Add master data first.'
                );
            }

            $branchId = $branches->first();
            $userId = $users->first();
            $categoryIds = $categories->values()->all();
            $brandIds = $brands->values()->all();

            $gst5Record = $gstRates->firstWhere('rate', '5.00')
                ?? $gstRates->firstWhere('rate', 5)
                ?? $gstRates->first();

            $gst3Record = $gstRates->firstWhere('rate', '3.00')
                ?? $gstRates->firstWhere('rate', 3)
                ?? $gst5Record;

            $gst5 = $gst5Record->id;
            $gst3 = $gst3Record->id;

            $suppliers = [
                ['name' => 'Ahmedabad Wholesale Traders', 'gstin' => '24ABCDE1234F1Z5', 'contact' => '9876501001'],
                ['name' => 'Gujarat FMCG Distributors', 'gstin' => '24BCDEF2345G1Z6', 'contact' => '9876501002'],
                ['name' => 'Metro General Suppliers', 'gstin' => '24CDEFG3456H1Z7', 'contact' => '9876501003'],
                ['name' => 'Western India Products', 'gstin' => '24DEFGH4567J1Z8', 'contact' => '9876501004'],
            ];

            $supplierIds = [];

            foreach ($suppliers as $supplier) {
                $supplierIds[] = DB::table('suppliers')->insertGetId([
                    'store_id' => $storeId,
                    'name' => $supplier['name'],
                    'gstin' => $supplier['gstin'],
                    'contact' => $supplier['contact'],
                    'address' => 'Ahmedabad, Gujarat',
                    'state' => 'Gujarat',
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $customerData = [
                ['name' => 'Aarav Patel', 'mobile' => '9876502001', 'area' => 'Nikol'],
                ['name' => 'Priya Shah', 'mobile' => '9876502002', 'area' => 'Maninagar'],
                ['name' => 'Rahul Mehta', 'mobile' => '9876502003', 'area' => 'Bapunagar'],
                ['name' => 'Neha Desai', 'mobile' => '9876502004', 'area' => 'Vastral'],
                ['name' => 'Karan Joshi', 'mobile' => '9876502005', 'area' => 'Naroda'],
                ['name' => 'Anjali Parmar', 'mobile' => '9876502006', 'area' => 'Nikol'],
                ['name' => 'Rohan Trivedi', 'mobile' => '9876502007', 'area' => 'Odhav'],
                ['name' => 'Isha Modi', 'mobile' => '9876502008', 'area' => 'Maninagar'],
            ];

            foreach ($customerData as $customer) {
                DB::table('customers')->updateOrInsert(
                    [
                        'branch_id' => $branchId,
                        'mobile' => $customer['mobile'],
                    ],
                    [
                        'name' => $customer['name'],
                        'add1' => $customer['area'] . ', Ahmedabad',
                        'area' => $customer['area'],
                        'city' => 'Ahmedabad',
                        'opening_balance' => 0,
                        'loyalty_points' => 0,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }


            $products = [
                ['name' => 'Maggie Noodles 70g', 'sku' => 'DEMO-MAG-070', 'barcode' => '8901000000001', 'mrp' => 14, 'price' => 14, 'cost' => 10, 'gst' => $gst5, 'category' => 1, 'brand' => 1],
                ['name' => 'Balaji Wafers 50g', 'sku' => 'DEMO-BAL-050', 'barcode' => '8901000000002', 'mrp' => 20, 'price' => 20, 'cost' => 14, 'gst' => $gst5, 'category' => 1, 'brand' => 1],
                ['name' => 'Cola Bottle 750ml', 'sku' => 'DEMO-COL-750', 'barcode' => '8901000000003', 'mrp' => 40, 'price' => 40, 'cost' => 28, 'gst' => $gst5, 'category' => 1, 'brand' => 1],
                ['name' => 'Biscuits Family Pack', 'sku' => 'DEMO-BIS-001', 'barcode' => '8901000000004', 'mrp' => 30, 'price' => 30, 'cost' => 21, 'gst' => $gst5, 'category' => 1, 'brand' => 1],
                ['name' => 'Chocolate Bar', 'sku' => 'DEMO-CHO-001', 'barcode' => '8901000000005', 'mrp' => 25, 'price' => 25, 'cost' => 17, 'gst' => $gst5, 'category' => 1, 'brand' => 1],
                ['name' => 'USB Keyboard', 'sku' => 'DEMO-KEY-001', 'barcode' => '8901000000006', 'mrp' => 499, 'price' => 449, 'cost' => 320, 'gst' => $gst3, 'category' => 0, 'brand' => 0],
                ['name' => 'Wireless Mouse', 'sku' => 'DEMO-MOU-001', 'barcode' => '8901000000007', 'mrp' => 699, 'price' => 599, 'cost' => 420, 'gst' => $gst3, 'category' => 0, 'brand' => 0],
                ['name' => 'USB-C Cable', 'sku' => 'DEMO-CAB-001', 'barcode' => '8901000000008', 'mrp' => 249, 'price' => 199, 'cost' => 110, 'gst' => $gst3, 'category' => 0, 'brand' => 0],
                ['name' => 'Power Bank 10000mAh', 'sku' => 'DEMO-PWB-010', 'barcode' => '8901000000009', 'mrp' => 1499, 'price' => 1299, 'cost' => 950, 'gst' => $gst3, 'category' => 0, 'brand' => 1],
                ['name' => 'Bluetooth Speaker', 'sku' => 'DEMO-SPK-001', 'barcode' => '8901000000010', 'mrp' => 1999, 'price' => 1699, 'cost' => 1250, 'gst' => $gst3, 'category' => 0, 'brand' => 1],
            ];


            foreach ($products as $index => $product) {
                $categoryId = $product['category'] == 1
                    ? 3
                    : 2;

                $brandId = $product['brand'] == 1
                    ? 1
                    : 2;

                DB::table('products')->updateOrInsert(
                    ['sku' => $product['sku']],
                    [
                        'store_id' => $storeId,
                        'barcode' => $product['barcode'],
                        'name' => $product['name'],
                        'brand_id' => $brandId,
                        'category_id' => $categoryId,
                        'hsn_code' => $categoryId == 3 ? '190590' : '847160',
                        'gst_rate_id' => $product['gst'],
                        'mrp' => $product['mrp'],
                        'selling_price' => $product['price'],
                        'cost_price' => $product['cost'],
                        'created_by' => $userId,
                        'updated_by' => $userId,
                        'stock' => 0,
                        'gst_inclusive' => 1,
                        'is_price_override' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }

            // Create purchase bills and inventory batches
            $demoProducts = DB::table('products')
                ->where('store_id', $storeId)
                ->where('sku', 'like', 'DEMO-%')
                ->orderBy('id')
                ->get()
                ->keyBy('sku');

            $purchaseGroups = [
                ['supplier' => 0, 'skus' => ['DEMO-MAG-070', 'DEMO-BAL-050', 'DEMO-COL-750']],
                ['supplier' => 1, 'skus' => ['DEMO-BIS-001', 'DEMO-CHO-001']],
                ['supplier' => 2, 'skus' => ['DEMO-KEY-001', 'DEMO-MOU-001', 'DEMO-CAB-001']],
                ['supplier' => 3, 'skus' => ['DEMO-PWB-010', 'DEMO-SPK-001']],
            ];

            foreach ($purchaseGroups as $groupIndex => $group) {
                $billDate = now()->subDays(10 - $groupIndex * 2)->toDateString();
                $billId = DB::table('purchase_bills')->insertGetId([
                    'store_id' => $storeId,
                    'branch_id' => $branchId,
                    'supplier_id' => $supplierIds[$group['supplier']],
                    'bill_no' => 'DEMO-PUR-' . now()->format('Ymd') . '-' . ($groupIndex + 1),
                    'inward_no' => 'DEMO-IN-' . ($groupIndex + 1),
                    'inward_sequence' => $groupIndex + 1,
                    'bill_date' => $billDate,
                    'tax_type' => 'exclusive',
                    'taxable_value' => 0,
                    'cgst_amount' => 0,
                    'sgst_amount' => 0,
                    'igst_amount' => 0,
                    'cess_amount' => 0,
                    'total_tax' => 0,
                    'total_amount' => 0,
                    'settlement_amount' => 0,
                    'notes' => 'Demo test purchase',
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'received' => 1,
                    'is_lost' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $billTaxable = 0;
                $billTax = 0;
                $billTotal = 0;

                foreach ($group['skus'] as $skuIndex => $sku) {
                    $product = $demoProducts->get($sku);

                    if (! $product) {
                        throw new RuntimeException("Demo product missing: {$sku}");
                    }

                    $qty = 50;
                    $rate = (float) $product->cost_price;
                    $taxRate = (float) DB::table('gst_rates')
                        ->where('id', $product->gst_rate_id)
                        ->value('rate');

                    $taxable = round($qty * $rate, 2);
                    $tax = round($taxable * $taxRate / 100, 2);
                    $cgst = round($tax / 2, 2);
                    $sgst = $tax - $cgst;
                    $total = $taxable + $tax;
                    $batchNo = 'DEMO-' . ($groupIndex + 1) . '-' . ($skuIndex + 1);

                    $lineId = DB::table('purchase_lines')->insertGetId([
                        'purchase_bill_id' => $billId,
                        'product_id' => $product->id,
                        'gst_rate_id' => $product->gst_rate_id,
                        'qty' => $qty,
                        'free_qty' => 0,
                        'purchase_rate' => $rate,
                        'amount' => $total,
                        'discount_type' => null,
                        'discount' => 0,
                        'hsn_code' => $product->hsn_code,
                        'batch_no' => $batchNo,
                        'expiry_date' => now()->addMonths(12)->toDateString(),
                        'taxable_value' => $taxable,
                        'total_gst' => $tax,
                        'cgst' => $cgst,
                        'sgst' => $sgst,
                        'igst' => 0,
                        'mrp' => $product->mrp,
                        'selling_price' => $product->selling_price,
                        'is_opening' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('inventories')->insert([
                        'product_id' => $product->id,
                        'branch_id' => $branchId,
                        'purchase_bill_id' => $billId,
                        'purchase_line_id' => $lineId,
                        'qty' => $qty,
                        'sold_qty' => 0,
                        'expired_qty' => 0,
                        'free' => 0,
                        'batch_no' => $batchNo,
                        'mrp' => $product->mrp,
                        'selling_price' => $product->selling_price,
                        'cost_price' => $rate,
                        'expiry_date' => now()->addMonths(12)->toDateString(),
                        'is_opening' => 0,
                        'rate' => $rate,
                        'amount' => $taxable,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('products')
                        ->where('id', $product->id)
                        ->increment('stock', $qty);

                    $billTaxable += $taxable;
                    $billTax += $tax;
                    $billTotal += $total;
                }

                DB::table('purchase_bills')
                    ->where('id', $billId)
                    ->update([
                        'taxable_value' => $billTaxable,
                        'cgst_amount' => round($billTax / 2, 2),
                        'sgst_amount' => $billTax - round($billTax / 2, 2),
                        'total_tax' => $billTax,
                        'total_amount' => $billTotal,
                        'settlement_amount' => $billTotal,
                        'updated_at' => $now,
                    ]);

                // Supplier payment: first two bills paid, others partially paid
                $paymentAmount = $groupIndex < 2
                    ? $billTotal
                    : round($billTotal * 0.5, 2);

                DB::table('purchase_payments')->insert([
                    'purchase_bill_id' => $billId,
                    'amount' => $paymentAmount,
                    'method' => $groupIndex % 2 === 0 ? 'bank' : 'cash',
                    'reference' => 'DEMO-PAY-' . ($groupIndex + 1),
                    'payment_date' => $billDate,
                    'status' => 'success',
                    'updated_by' => $userId,
                    'remarks' => 'Demo supplier payment',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->command->info('Created demo purchase bills, purchase lines, inventory batches and supplier payments.');

            $this->command->info('Created 4 suppliers, 8 customers and 10 products.');
            $this->command->warn('This starter seeder does not delete existing data or create purchase/sales transactions yet.');
        });
    }
}
   // Existing run() method yahin rahega
