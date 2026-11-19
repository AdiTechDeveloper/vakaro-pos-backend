<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Product import ka demo template.
 *
 *  - Normal mart store  => new ProductTemplateExport(false)  (weight columns, pharmacy columns NAHI)
 *  - Pharmacy store     => new ProductTemplateExport(true)   (pharmacy columns, weight columns NAHI)
 *
 * Import ke time pharmacy-only columns non-pharmacy store par ignore hote hain,
 * aur sale_type column na ho to product 'unit' maana jata hai.
 */
class ProductTemplateExport implements WithMultipleSheets
{
    public function __construct(private bool $pharmacy = false)
    {
    }

    public function sheets(): array
    {
        return [
            new ProductDataTemplateSheet($this->pharmacy),
            new ProductInstructionsSheet($this->pharmacy),
        ];
    }
}

/**
 * Column lists (ek jagah, taaki dono sheets same columns use karein)
 */
class ProductTemplateColumns
{
    // Har store ke liye
    public const COMMON = [
        'name', 'sku', 'barcode', 'brand', 'category', 'hsn_code',
        'gst_rate_id', 'cost_price', 'gst_inclusive', 'is_price_override',
    ];

    // Sirf mart / normal store (weight based selling)
    public const MART = ['sale_type', 'plu_code', 'price_per_kg'];

    // Sirf pharmacy store
    public const PHARMACY = [
        'salt_composition', 'manufacturer', 'drug_schedule',
        'requires_prescription', 'pack_type', 'units_per_pack',
    ];

    // Text format wale columns (leading zero / lambe barcode kharab na hon)
    public const TEXT = [
        'sku', 'barcode', 'hsn_code', 'sale_type', 'plu_code',
        'salt_composition', 'manufacturer', 'drug_schedule', 'pack_type',
    ];

    public static function keys(bool $pharmacy): array
    {
        return array_merge(self::COMMON, $pharmacy ? self::PHARMACY : self::MART);
    }
}

/**
 * Sheet 1: Products
 */
class ProductDataTemplateSheet implements
    FromArray,
    WithHeadings,
    ShouldAutoSize,
    WithColumnFormatting,
    WithTitle
{
    public function __construct(private bool $pharmacy = false)
    {
    }

    public function title(): string
    {
        return 'Products';
    }

    public function headings(): array
    {
        return ProductTemplateColumns::keys($this->pharmacy);
    }

    public function array(): array
    {
        $keys = ProductTemplateColumns::keys($this->pharmacy);

        $samples = $this->pharmacy ? $this->pharmacySamples() : $this->martSamples();

        return array_map(
            fn (array $row) => array_map(fn ($key) => $row[$key] ?? '', $keys),
            $samples
        );
    }

    public function columnFormats(): array
    {
        $formats = [];

        foreach (ProductTemplateColumns::keys($this->pharmacy) as $index => $key) {
            if (in_array($key, ProductTemplateColumns::TEXT, true)) {
                $formats[Coordinate::stringFromColumnIndex($index + 1)] = NumberFormat::FORMAT_TEXT;
            }
        }

        return $formats;
    }

    private function martSamples(): array
    {
        return [
            [
                'name' => 'SAMPLE - DELETE THIS ROW',
                'sku' => 'SAMPLE-SKU-001',
                'barcode' => '',
                'brand' => 'Sample Brand',
                'category' => 'Sample Category',
                'hsn_code' => '123456',
                'gst_rate_id' => '',
                'cost_price' => 50,
                'gst_inclusive' => 1,
                'is_price_override' => 0,
                'sale_type' => 'unit',
                'plu_code' => '',
                'price_per_kg' => '',
            ],
            [
                'name' => 'SAMPLE - DELETE THIS ROW',
                'sku' => 'SAMPLE-RICE-001',
                'barcode' => '',
                'brand' => 'Sample Brand',
                'category' => 'Grains',
                'hsn_code' => '100630',
                'gst_rate_id' => '',
                'cost_price' => 45,
                'gst_inclusive' => 0,
                'is_price_override' => 0,
                'sale_type' => 'weight',
                'plu_code' => 'RICE01',
                'price_per_kg' => 70,
            ],
        ];
    }

    private function pharmacySamples(): array
    {
        return [
            [
                'name' => 'SAMPLE - DELETE THIS ROW',
                'sku' => 'SAMPLE-MED-001',
                'barcode' => '',
                'brand' => 'Sample Pharma Brand',
                'category' => 'Tablets',
                'hsn_code' => '3004',
                'gst_rate_id' => '',
                'cost_price' => 26,
                'gst_inclusive' => 1,
                'is_price_override' => 0,
                'salt_composition' => 'Paracetamol 650mg',
                'manufacturer' => 'Sample Pharma Ltd',
                'drug_schedule' => 'H',
                'requires_prescription' => 1,
                'pack_type' => 'strip',
                'units_per_pack' => 15,
            ],
            [
                'name' => 'SAMPLE - DELETE THIS ROW',
                'sku' => 'SAMPLE-MED-002',
                'barcode' => '',
                'brand' => 'Sample Pharma Brand',
                'category' => 'Syrups',
                'hsn_code' => '3004',
                'gst_rate_id' => '',
                'cost_price' => 60,
                'gst_inclusive' => 1,
                'is_price_override' => 0,
                'salt_composition' => 'Dextromethorphan + Chlorpheniramine',
                'manufacturer' => 'Sample Pharma Ltd',
                'drug_schedule' => 'none',
                'requires_prescription' => 0,
                'pack_type' => 'bottle',
                'units_per_pack' => 1,
            ],
        ];
    }
}

/**
 * Sheet 2: Instructions
 */
class ProductInstructionsSheet implements
    FromArray,
    WithHeadings,
    ShouldAutoSize,
    WithTitle
{
    public function __construct(private bool $pharmacy = false)
    {
    }

    public function title(): string
    {
        return 'Instructions';
    }

    public function headings(): array
    {
        return [
            'Column',
            'Required',
            'Description',
            'Example / Allowed Values',
        ];
    }

    public function array(): array
    {
        $p = $this->pharmacy;

        // [group, column, required, description, example]
        // group: all = dono store types, mart = sirf mart, pharmacy = sirf pharmacy
        $rows = [
            ['all', 'IMPORTANT', 'Yes', 'Delete all sample product rows before importing.', 'Remove rows containing SAMPLE - DELETE THIS ROW.'],
            ['all', 'name', 'Yes', 'Product name.', $p ? 'Dolo 650 Tablet' : 'Rice'],
            ['all', 'sku', 'No', 'Unique SKU.', $p ? 'MED-001' : 'RICE-001'],
            ['all', 'barcode', 'No', 'Leave blank to generate a barcode automatically.', '8901234567890'],
            ['all', 'brand', 'No', 'Brand name. A missing brand may be created automatically.', $p ? 'Micro Labs' : 'ABC Foods'],
            ['all', 'category', 'No', 'Category name. A missing category may be created automatically.', $p ? 'Tablets' : 'Grains'],
            ['all', 'hsn_code', 'No', 'HSN code.', $p ? '3004' : '100630'],
            ['all', 'gst_rate_id', 'No', 'Existing ID from the gst_rates table.', 'Check your database for the correct ID.'],
            ['all', 'cost_price', 'No', 'Product cost price. Enter a non-negative number.', $p ? '26' : '45'],
            ['all', 'gst_inclusive', 'No', 'Whether the selling price includes GST.', '1 = Yes, 0 = No'],
            ['all', 'is_price_override', 'No', 'Whether price override is enabled.', '1 = Yes, 0 = No'],

            // ---- Mart only ----
            ['mart', 'sale_type', 'Yes', 'Product selling type.', 'unit or weight'],
            ['mart', 'plu_code', 'Conditional', 'PLU code for weight-based products. Must be unique within the store.', 'RICE01'],
            ['mart', 'price_per_kg', 'Conditional', 'Selling price per kg for weight-based products.', '70'],
            ['mart', 'Weight product', 'Important', 'For sale_type=weight, PLU code and price_per_kg are required.', 'Rice, weight, RICE01, 70'],
            ['mart', 'Unit product', 'Important', 'For sale_type=unit, PLU code and price_per_kg can remain blank.', 'Parle-G, unit'],

            // ---- Pharmacy only ----
            ['pharmacy', 'salt_composition', 'Yes', 'Salt / generic composition of the medicine. Compulsory for every row.', 'Paracetamol 650mg'],
            ['pharmacy', 'manufacturer', 'Yes', 'Manufacturer / company name. Compulsory for every row.', 'Micro Labs Ltd'],
            ['pharmacy', 'drug_schedule', 'Yes', 'Drug schedule. Compulsory. Write none if the product has no schedule.', 'none, G, H, H1 or X'],
            ['pharmacy', 'requires_prescription', 'No', 'Whether a prescription is needed. Optional: it is always 1 for schedule H, H1 and X.', '1 = Yes, 0 = No'],
            ['pharmacy', 'pack_type', 'Yes', 'Pack type (maximum 30 characters). Compulsory.', 'strip, bottle, tube, vial'],
            ['pharmacy', 'units_per_pack', 'Yes', 'Number of units in one pack. Whole number, 1 or more. Compulsory.', '15'],
            ['pharmacy', 'Medicine product', 'Important', 'Rows with a missing compulsory pharmacy column are skipped and reported with the row number.', 'Dolo 650, Paracetamol 650mg, Micro Labs Ltd, H, strip, 15'],

            // ---- Common notes ----
            ['all', 'Prices', 'Info', 'MRP and inventory selling prices are not imported by this template.', 'Only cost_price is imported here.'],
            ['all', 'General rule', 'Important', 'Keep headings unchanged. Maximum 2,000 product rows per import.', 'Use one row per product.'],
        ];

        $mode = $p ? 'pharmacy' : 'mart';

        return array_values(array_map(
            fn (array $row) => array_slice($row, 1),
            array_filter($rows, fn (array $row) => in_array($row[0], ['all', $mode], true))
        ));
    }
}