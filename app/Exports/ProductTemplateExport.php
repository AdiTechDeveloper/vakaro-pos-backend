<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;


/**
 * Empty product import template: headings only.
 * SKU, barcode and HSN columns are formatted as TEXT so Excel does not turn
 * long numbers into 8.9E+12 or drop leading zeros.
 */
class ProductTemplateExport implements FromArray, WithHeadings, ShouldAutoSize, WithColumnFormatting
{
    public function headings(): array
    {
        return [
            'name',              // A  required
            'sku',               // B
            'barcode',           // C  (empty = auto generated)
            'brand',             // D  (created automatically if new)
            'category',          // E  (created automatically if new)
            'hsn_code',          // F
            'gst_rate_id',       // G
            'mrp',               // H
            'selling_price',     // I
            'cost_price',        // J
            'gst_inclusive',     // K  1/0 or yes/no
            'is_price_override', // L  1/0 or yes/no
        ];
    }

    public function array(): array
    {
        return [];
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