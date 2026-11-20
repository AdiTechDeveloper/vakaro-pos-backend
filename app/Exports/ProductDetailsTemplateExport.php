<?php

namespace App\Exports;

use App\Support\ProductDetailsColumns;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Store ke saare products + unki abhi ki description / details.
 * User ise download karke khali cells bharta hai aur wapas upload kar deta hai.
 */
class ProductDetailsTemplateExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithTitle
{
    public function __construct(private array $rows)
    {
    }

    public function title(): string
    {
        return 'Product Details';
    }

    public function headings(): array
    {
        return ProductDetailsColumns::headings();
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function columnFormats(): array
    {
        $formats = [];

        foreach (ProductDetailsColumns::headings() as $index => $key) {
            // sku / barcode text rahe, taaki leading zero aur lambe barcode kharab na hon
            if (in_array($key, ['sku', 'barcode'], true)) {
                $formats[Coordinate::stringFromColumnIndex($index + 1)] = NumberFormat::FORMAT_TEXT;
            }
        }

        return $formats;
    }
}