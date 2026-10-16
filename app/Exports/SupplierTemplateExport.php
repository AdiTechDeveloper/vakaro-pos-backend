<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class SupplierTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new SupplierDataTemplateSheet(),
            new SupplierInstructionsSheet(),
        ];
    }
}

/**
 * Sheet 1: Suppliers
 *
 * Keep the column names compatible with the existing importer.
 */
class SupplierDataTemplateSheet implements
    FromArray,
    WithHeadings,
    ShouldAutoSize,
    WithColumnFormatting,
    WithTitle
{
    public function title(): string
    {
        return 'Suppliers';
    }

    public function headings(): array
    {
        return [
            'name',
            'gstin',
            'contact',
            'address',
            'state',
        ];
    }

    public function array(): array
    {
        return [
            [
                'SAMPLE - DELETE THIS ROW',
                '',
                '9876543210',
                '123 Sample Street, Ahmedabad',
                'Gujarat',
            ],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT,
            'C' => NumberFormat::FORMAT_TEXT,
        ];
    }
}

/**
 * Sheet 2: Instructions
 *
 * Explains each supplier field and provides examples.
 */
class SupplierInstructionsSheet implements
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
                'Delete the sample row from the Suppliers sheet before importing.',
                'Remove the row containing SAMPLE - DELETE THIS ROW.',
            ],
            [
                'name',
                'Yes',
                'Supplier or business name. Cannot be empty.',
                'ABC Traders',
            ],
            [
                'gstin',
                'Conditional',
                'GST Identification Number. Enter a valid 15-character GSTIN if available.',
                'Use the supplier actual GSTIN.',
            ],
            [
                'contact',
                'No',
                'Supplier contact mobile number. Keep it as text to preserve digits.',
                '9876543210',
            ],
            [
                'address',
                'No',
                'Supplier business or billing address.',
                '123 Sample Street, Ahmedabad',
            ],
            [
                'state',
                'Conditional',
                'State is required unless a valid GSTIN is provided and the importer derives the state from it.',
                'Gujarat',
            ],
            [
                'General rule',
                'Important',
                'Keep the column headings unchanged and enter supplier data under the matching columns.',
                'Maximum 2,000 suppliers per import, subject to backend validation.',
            ],
        ];
    }
}
