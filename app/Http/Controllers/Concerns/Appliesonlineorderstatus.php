<?php

namespace App\Http\Controllers\Concerns;

use App\Models\OnlineOrder;
use App\Services\OnlineOrderBilling;
use Illuminate\Support\Facades\DB;

/**
 * Order ka status badalne ka ek hi code. POS staff (OnlineOrderController) aur
 * delivery person (DeliveryOrderController) dono yahi use karte hain, taaki
 * "delivered" par bill / stock ka logic sirf ek jagah rahe.
 */
trait AppliesOnlineOrderStatus
{
    /**
     * @param  OnlineOrder  $order  'items' ke saath load kiya hua
     * @return array{message:string,code:int}|null  null = ho gaya, warna error
     */
    protected function applyOnlineStatus(OnlineOrder $order, string $to, $user, ?string $reason = null): ?array
    {
        if (! in_array($to, OnlineOrder::TRANSITIONS[$order->status] ?? [], true)) {
            return ['message' => "Order is {$order->status}. It cannot be changed to {$to}.", 'code' => 409];
        }
        if ($to === 'out_for_delivery' && $order->mode !== 'delivery') {
            return ['message' => 'This is a pickup order.', 'code' => 422];
        }
        if ($to === 'cancelled' && empty($reason)) {
            return ['message' => 'Please give a reason for cancelling.', 'code' => 422];
        }

        // Deliver = POS bill banao (stock katega). Bill pehle, status baad mein: bill fail ho to order "delivered" nahi hota.
        $billId = $order->sales_bill_id;
        if ($to === 'delivered' && ! $billId) {
            $res = app(OnlineOrderBilling::class)->createBill($order, $user);
            if (! $res['ok']) {
                return ['message' => $res['message'], 'code' => 422];
            }
            $billId = $res['bill_id'];
        }

        $conflict = DB::transaction(function () use ($order, $to, $reason, $user, $billId) {
            // Doosre ne beech mein status badal diya ho to pata chal jaye
            $fresh = OnlineOrder::lockForUpdate()->find($order->id);
            if (! in_array($to, OnlineOrder::TRANSITIONS[$fresh->status] ?? [], true) && $fresh->status !== $to) {
                return "Order was just changed to {$fresh->status} by someone else.";
            }

            $update = ['status' => $to];
            if ($to === 'delivered') {
                $update += [
                    'delivered_at' => now(),
                    'sales_bill_id' => $billId,
                    'payment_status' => 'paid', // COD / store par cash mil gaya
                ];
            }
            if ($to === 'cancelled') {
                $update += ['cancelled_at' => now(), 'cancel_reason' => $reason];
            }
            $fresh->update($update);

            $fresh->events()->create([
                'status' => $to,
                'actor' => 'staff',
                'user_id' => $user->id,
                'note' => $reason,
            ]);

            return null;
        });

        return $conflict ? ['message' => $conflict, 'code' => 409] : null;
    }
}