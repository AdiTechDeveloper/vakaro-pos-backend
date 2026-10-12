<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class SalesReportExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(
        private Collection $rows
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn ($row) => [
            $row->bill_no ?? '',
            $row->created_at ?? '',
            $row->customer_name ?? 'Walk-in Customer',
            $row->subtotal ?? 0,
            $row->total_gst ?? 0,
            $row->total_amount ?? 0,
            $row->paid_amount ?? 0,
            $row->due_amount ?? 0,
            $row->total_profit ?? 0,
            $row->payment_status ?? '',
            $row->bill_status ?? '',
            $row->payment_methods ?? 'N/A',
        ]);
    }

    public function headings(): array
    {
        return [
            'Bill No',
            'Date',
            'Customer',
            'Subtotal',
            'GST',
            'Total Amount',
            'Paid Amount',
            'Due Amount',
            'Profit',
            'Payment Status',
            'Bill Status',
            'Payment Methods',
        ];
    }
}
