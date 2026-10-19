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
        // Keep Products as the first sheet because the importer
        // currently reads the first worksheet.
        return [
            new ProductDataTemplateSheet(),
            new ProductInstructionsSheet(),
        ];
    }
}

/**
 * Sheet 1: Products
 *
 * Keep these headings compatible with ProductRowsImport.
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
            'mrp',
            'selling_price',
            'cost_price',
            'gst_inclusive',
            'is_price_override',
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
                100,
                80,
                50,
                1,
                0,
            ],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT,
            'C' => NumberFormat::FORMAT_TEXT,
            'F' => NumberFormat::FORMAT_TEXT,
        ];
    }
}

/**
 * Sheet 2: Instructions
 *
 * Explains every import field and provides examples.
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
                'Delete the sample product row from the Products sheet before importing.',
                'Remove the row containing SAMPLE - DELETE THIS ROW.',
            ],
            [
                'name',
                'Yes',
                'Product name. This field cannot be empty.',
                'Aloo Tikki Burger',
            ],
            [
                'sku',
                'No',
                'Unique stock keeping unit. Leave blank if not needed.',
                'BURGER-001',
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
                'Brand name. A new brand may be created automatically.',
                'ABC Foods',
            ],
            [
                'category',
                'No',
                'Category name. A new category may be created automatically.',
                'Burgers',
            ],
            [
                'hsn_code',
                'No',
                'HSN code. Enter it as text to preserve leading zeros.',
                '123456',
            ],
            [
                'gst_rate_id',
                'No',
                'Use an existing ID from the gst_rates table. Invalid IDs are saved as blank.',
                'Check your database for the correct ID.',
            ],
            [
                'mrp',
                'No',
                'Maximum retail price. Enter a non-negative number.',
                '100',
            ],
            [
                'selling_price',
                'No',
                'Selling price. Enter a non-negative number.',
                '80',
            ],
            [
                'cost_price',
                'No',
                'Purchase or cost price. Enter a non-negative number.',
                '50',
            ],
            [
                'gst_inclusive',
                'No',
                'Whether the selling price includes GST.',
                '1 = Yes, 0 = No; yes/no also accepted.',
            ],
            [
                'is_price_override',
                'No',
                'Whether the product has a price override enabled.',
                '1 = Yes, 0 = No; yes/no also accepted.',
            ],
            [
                'General rule',
                'Important',
                'Keep all column headings unchanged and enter data under the matching columns.',
                'Maximum 2,000 product rows per import.',
            ],
        ];
    }
}
