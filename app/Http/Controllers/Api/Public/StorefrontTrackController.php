<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer apne order ke delivery wale ki live location dekhta hai.
 * Sirf apna order, sirf jab order raste me ho, aur sirf location + naam (phone number nahi).
 */
class StorefrontTrackController extends Controller
{
    // Isse purani location "nahi mili" maani jati hai (delivery wale ka phone band / net nahi)
    private const FRESH_SECONDS = 300;

    // GET /api/public/orders/{orderNo}/track
    public function show(Request $request, string $orderNo): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $order = OnlineOrder::with('deliveryUser:id,name')
            ->where('storefront_customer_id', $customer->id)
            ->where('order_no', $orderNo)
            ->first();

        if (! $order) {
            return response()->json(['status' => false, 'message' => 'Order not found.'], 404);
        }

        $live = $order->mode === 'delivery' && $order->status === 'out_for_delivery';
        $age = $order->rider_ts ? max(0, time() - (int) $order->rider_ts) : null;
        $fresh = $live && $age !== null && $age <= self::FRESH_SECONDS;

        return response()->json([
            'status' => true,
            'data' => [
                'order_status' => $order->status,
                'live' => $live,
                'destination' => ($order->addr_lat && $order->addr_lng)
                    ? ['lat' => $order->addr_lat, 'lng' => $order->addr_lng]
                    : null,
                'rider' => $fresh
                    ? ['lat' => $order->rider_lat, 'lng' => $order->rider_lng, 'seconds_ago' => $age]
                    : null,
                'rider_name' => $live ? $order->deliveryUser?->name : null,
                'distance_km' => $fresh ? $order->distanceToCustomerKm() : null,
                // Delivery wale ki jagah se andaza; location na ho to order ka apna ETA
                'eta_minutes' => $fresh ? ($order->liveEtaMinutes() ?? $order->etaMinutes()) : $order->etaMinutes(),
            ],
        ]);
    }
}