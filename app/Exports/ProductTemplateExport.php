<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ProductTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new ProductDataTemplateSheet(),
            new ProductInstructionsSheet(),
        ];
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
    public function title(): string
    {
        return 'Products';
    }

    public function headings(): array
    {
        return [
            'name',
            'sku',
            'barcode',
            'brand',
            'category',
            'hsn_code',
            'gst_rate_id',
            'cost_price',
            'gst_inclusive',
            'is_price_override',
            'sale_type',
            'plu_code',
            'price_per_kg',
        ];
    }

    public function array(): array
    {
        return [
            [
                'SAMPLE - DELETE THIS ROW',
                'SAMPLE-SKU-001',
                '',
                'Sample Brand',
                'Sample Category',
                '123456',
                '',
                50,
                1,
                0,
                'unit',
                '',
                '',
            ],
            [
                'SAMPLE - DELETE THIS ROW',
                'SAMPLE-RICE-001',
                '',
                'Sample Brand',
                'Grains',
                '100630',
                '',
                45,
                0,
                0,
                'weight',
                'RICE01',
                70,
            ],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT,
            'C' => NumberFormat::FORMAT_TEXT,
            'F' => NumberFormat::FORMAT_TEXT,
            'K' => NumberFormat::FORMAT_TEXT,
            'L' => NumberFormat::FORMAT_TEXT,
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
        return [
            [
                'IMPORTANT',
                'Yes',
                'Delete both sample product rows before importing.',
                'Remove rows containing SAMPLE - DELETE THIS ROW.',
            ],
            [
                'name',
                'Yes',
                'Product name.',
                'Rice',
            ],
            [
                'sku',
                'No',
                'Unique SKU.',
                'RICE-001',
            ],
            [
                'barcode',
                'No',
                'Leave blank to generate a barcode automatically.',
                '8901234567890',
            ],
            [
                'brand',
                'No',
                'Brand name. A missing brand may be created automatically.',
                'ABC Foods',
            ],
            [
                'category',
                'No',
                'Category name. A missing category may be created automatically.',
                'Grains',
            ],
            [
                'hsn_code',
                'No',
                'HSN code.',
                '100630',
            ],
            [
                'gst_rate_id',
                'No',
                'Existing ID from the gst_rates table.',
                'Check your database for the correct ID.',
            ],
            [
                'cost_price',
                'No',
                'Product cost price. Enter a non-negative number.',
                '45',
            ],
            [
                'gst_inclusive',
                'No',
                'Whether the selling price includes GST.',
                '1 = Yes, 0 = No',
            ],
            [
                'is_price_override',
                'No',
                'Whether price override is enabled.',
                '1 = Yes, 0 = No',
            ],
            [
                'sale_type',
                'Yes',
                'Product selling type.',
                'unit or weight',
            ],
            [
                'plu_code',
                'Conditional',
                'PLU code for weight-based products. Must be unique within the store.',
                'RICE01',
            ],
            [
                'price_per_kg',
                'Conditional',
                'Selling price per kg for weight-based products.',
                '70',
            ],
            [
                'Weight product',
                'Important',
                'For sale_type=weight, PLU code and price_per_kg are required.',
                'Rice, weight, RICE01, 70',
            ],
            [
                'Unit product',
                'Important',
                'For sale_type=unit, PLU code and price_per_kg can remain blank.',
                'Parle-G, unit',
            ],
            [
                'Prices',
                'Info',
                'MRP and inventory selling prices are not imported by this template.',
                'Only cost_price is imported here.',
            ],
            [
                'General rule',
                'Important',
                'Keep headings unchanged. Maximum 2,000 product rows per import.',
                'Use one row per product.',
            ],
        ];
    }
}
