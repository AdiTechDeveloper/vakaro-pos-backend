<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Dashboard sales report (Excel / CSV).
 *
 * Place at: app/Exports/DashboardSalesReportExport.php
 * Your existing app/Exports/SalesReportExport.php is NOT touched.
 *
 * Excel: 3 sheets (Summary, Breakdown, Bills).
 * CSV:   only the Bills sheet (CSV cannot hold several sheets).
 */
class DashboardSalesReportExport implements WithMultipleSheets
{
    public function __construct(
        private array $summary,
        private array $breakdown,
        private array $bills,
        private bool $billsOnly = false
    ) {
    }

    public function sheets(): array
    {
        $bills = new DashboardSalesBillsSheet($this->bills);

        if ($this->billsOnly) {
            return [$bills];
        }

        return [
            new DashboardSalesSummarySheet($this->summary),
            new DashboardSalesBreakdownSheet($this->breakdown),
            $bills,
        ];
    }
}

class DashboardSalesSummarySheet implements FromArray, WithHeadings, ShouldAutoSize, WithTitle
{
    public function __construct(private array $rows)
    {
    }

    public function title(): string
    {
        return 'Summary';
    }

    public function headings(): array
    {
        return ['Metric', 'Value'];
    }

    public function array(): array
    {
        // 3rd value (type) sirf PDF ke liye hai, Excel me nahi dikhana
        return array_map(fn ($r) => [$r[0], $r[1]], $this->rows);
    }
}

class DashboardSalesBreakdownSheet implements FromArray, WithHeadings, ShouldAutoSize, WithTitle
{
    public function __construct(private array $rows)
    {
    }

    public function title(): string
    {
        return 'Breakdown';
    }

    public function headings(): array
    {
        return ['Period', 'Sales (INR)', 'Bills'];
    }

    public function array(): array
    {
        return $this->rows;
    }
}

class DashboardSalesBillsSheet implements FromArray, WithHeadings, ShouldAutoSize, WithTitle
{
    /** @param array<int, array<string, mixed>> $rows keyed rows from the controller */
    public function __construct(private array $rows)
    {
    }

    public function title(): string
    {
        return 'Bills';
    }

    public function headings(): array
    {
        return [
            'Bill No',
            'Date',
            'Time',
            'Customer',
            'Mobile',
            'Branch',
            'Subtotal (INR)',
            'GST (INR)',
            'Total (INR)',
            'Paid (INR)',
            'Due (INR)',
            'Profit (INR)',
            'Payment Status',
            'Bill Status',
            'Payment Methods',
        ];
    }

    public function array(): array
    {
        return array_map('array_values', $this->rows);
    }
}