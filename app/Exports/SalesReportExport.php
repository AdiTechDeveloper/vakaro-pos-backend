<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class SalesReportExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    /**
     * Online store ke orders se bane bills. Keys: "id:12" (bill id) ya "no:INV-9" (bill number).
     * Bill delivered hone par banta hai, aur online_orders.sales_bill_id usse jodta hai.
     */
    private array $online = [];

    public function __construct(
        private Collection $rows
    ) {
        $this->online = $this->findOnlineBills();
    }

    public function collection(): Collection
    {
        return $this->rows->map(fn ($row) => [
            $row->bill_no ?? '',
            $row->created_at ?? '',
            $row->customer_name ?? 'Walk-in Customer',
            $this->channelOf($row),
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
            'Channel',
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

    /** "POS" (counter billing) ya "Online" (online store order). */
    private function channelOf($row): string
    {
        // Agar report ki query ne khud "channel" de diya ho to wahi
        if (! empty($row->channel)) {
            return strtolower((string) $row->channel) === 'online' ? 'Online' : 'POS';
        }

        if (isset($row->id) && isset($this->online['id:'.$row->id])) {
            return 'Online';
        }

        if (isset($row->bill_no) && isset($this->online['no:'.$row->bill_no])) {
            return 'Online';
        }

        return 'POS';
    }

    /** Is report ke bills me se kaun se online orders se bane hain (ek hi baar, chunk me). */
    private function findOnlineBills(): array
    {
        $found = [];

        // 1) Bill id ho to seedha online_orders.sales_bill_id se
        $ids = $this->rows->map(fn ($r) => $r->id ?? null)->filter()->unique()->values();

        if ($ids->isNotEmpty()) {
            foreach ($ids->chunk(1000) as $chunk) {
                DB::table('online_orders')
                    ->whereIn('sales_bill_id', $chunk->all())
                    ->pluck('sales_bill_id')
                    ->each(function ($id) use (&$found) {
                        $found['id:'.$id] = true;
                    });
            }

            return $found;
        }

        // 2) Id na ho to bill number se (ye tabhi theek hai jab bill number har bill ka alag ho)
        $numbers = $this->rows->map(fn ($r) => $r->bill_no ?? null)->filter()->unique()->values();

        foreach ($numbers->chunk(1000) as $chunk) {
            DB::table('online_orders as oo')
                ->join('sales_bills as sb', 'sb.id', '=', 'oo.sales_bill_id')
                ->whereIn('sb.bill_no', $chunk->all())
                ->pluck('sb.bill_no')
                ->each(function ($no) use (&$found) {
                    $found['no:'.$no] = true;
                });
        }

        return $found;
    }
}