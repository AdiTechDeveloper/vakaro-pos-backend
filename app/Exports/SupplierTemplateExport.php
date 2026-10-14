<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Empty supplier import template: headings only.
 * GSTIN and contact columns are TEXT so Excel does not change the numbers.
 */
class SupplierTemplateExport implements FromArray, WithHeadings, ShouldAutoSize, WithColumnFormatting
{
    public function headings(): array
    {
        return [
            'name',     // A  required
            'gstin',    // B  15 characters (optional)
            'contact',  // C  10 digit mobile (optional)
            'address',  // D  (optional)
            'state',    // E  required, unless a valid GSTIN is given (state is taken from it)
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
        ];
    }
}