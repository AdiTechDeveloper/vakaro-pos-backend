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
     * @param  array  $opts  otp_verified (bool): delivery person ne OTP sahi daala
     *                       override (bool): manager/admin ne OTP ke bina deliver kiya (reason zaroori)
     * @return array{message:string,code:int}|null  null = ho gaya, warna error
     */
    protected function applyOnlineStatus(OnlineOrder $order, string $to, $user, ?string $reason = null, array $opts = []): ?array
    {
        if (! in_array($to, OnlineOrder::TRANSITIONS[$order->status] ?? [], true)) {
            return ['message' => "Order is {$order->status}. It cannot be changed to {$to}.", 'code' => 409];
        }
        if ($to === 'out_for_delivery' && $order->mode !== 'delivery') {
            return ['message' => 'This is a pickup order.', 'code' => 422];
        }
        // Delivery order ko raste mein bhejna sirf "assign" se hota hai (delivery person + customer OTP ke liye)
        if ($to === 'out_for_delivery') {
            return ['message' => 'Assign a delivery person to send this order out for delivery.', 'code' => 422];
        }
        // Delivery order sirf customer OTP se deliver hota hai (ya manager override, reason ke saath)
        $override = (bool) ($opts['override'] ?? false);
        if ($to === 'delivered' && $order->mode === 'delivery' && ! ($opts['otp_verified'] ?? false)) {
            if (! $override) {
                return ['message' => 'Delivery needs the customer OTP.', 'code' => 422];
            }
            if (mb_strlen(trim((string) $reason)) < 3) {
                return ['message' => 'Please give a reason for delivering without OTP.', 'code' => 422];
            }
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

        $conflict = DB::transaction(function () use ($order, $to, $reason, $user, $billId, $override, $opts) {
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
                    'delivery_otp_enc' => null,
                    'delivery_otp_attempts' => 0,
                    'delivery_otp_locked_until' => null,
                ];
                if ($fresh->payment_method === 'cod') {
                    $update['cod_collected'] = $fresh->total;
                }
                if ($fresh->mode === 'delivery' && ! ($opts['otp_verified'] ?? false) && $override) {
                    $update['delivery_otp_overridden'] = true;
                }
            }
            if ($to === 'cancelled') {
                $update += [
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                    'delivery_otp_enc' => null,
                    'delivery_otp_attempts' => 0,
                    'delivery_otp_locked_until' => null,
                ];
            }
            $fresh->update($update);

            $note = $reason;
            if ($to === 'delivered' && $fresh->mode === 'delivery') {
                $note = ($opts['otp_verified'] ?? false)
                    ? 'Delivered (customer OTP verified)'
                    : "Delivered without OTP (manager override): {$reason}";
            }

            $fresh->events()->create([
                'status' => $to,
                'actor' => 'staff',
                'user_id' => $user->id,
                'note' => $note,
            ]);

            return null;
        });

        return $conflict ? ['message' => $conflict, 'code' => 409] : null;
    }
}