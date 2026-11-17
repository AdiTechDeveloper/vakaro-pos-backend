<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales report ke bills me se kaun se "Online" (online store order) hain aur kaun se "POS" (counter billing).
 * Online order delivered hone par bill banta hai, aur online_orders.sales_bill_id usse jodta hai.
 * PDF aur Excel dono isi se channel lete hain, taaki dono me ek jaisa data aaye.
 */
class SalesChannelResolver
{
    /**
     * Har row par `channel` ("POS" / "Online") laga kar wapas deta hai.
     * Rows SalesReportService::getInvoiceTable() ki hone chahiye (sb.id zaroori).
     */
    public function annotate(Collection $rows): Collection
    {
        $online = [];

        foreach ($rows->pluck('id')->filter()->unique()->values()->chunk(1000) as $chunk) {
            DB::table('online_orders')
                ->whereIn('sales_bill_id', $chunk->all())
                ->pluck('sales_bill_id')
                ->each(function ($id) use (&$online) {
                    $online[(int) $id] = true;
                });
        }

        return $rows->map(function ($row) use ($online) {
            $row->channel = isset($online[(int) ($row->id ?? 0)]) ? 'Online' : 'POS';

            return $row;
        });
    }

    /** Channel ke hisaab se bills, sales aur profit (annotate() ke baad chalao). */
    public function summary(Collection $rows): array
    {
        $out = [];

        foreach (['POS', 'Online'] as $channel) {
            $set = $rows->where('channel', $channel);

            $out[$channel] = [
                'bills' => $set->count(),
                'total' => (float) $set->sum('total_amount'),
                'profit' => (float) $set->sum('total_profit'),
            ];
        }

        return $out;
    }
}