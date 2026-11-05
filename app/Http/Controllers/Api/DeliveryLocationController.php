<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Delivery wale ka phone har 15 second me apni location yahan bhejta hai (jab tak uska koi order raste me hai).
 * Location sirf usi ke "out_for_delivery" orders me jati hai, aur order khatam hote hi mita di jati hai (OnlineOrder model).
 */
class DeliveryLocationController extends Controller
{
    // POST /api/delivery/location   { lat, lng, accuracy? }
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:6,38'],
            'lng' => ['required', 'numeric', 'between:68,98'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        // Sirf is delivery wale ke apne, raste wale orders
        $updated = OnlineOrder::where('delivery_user_id', $request->user()->id)
            ->where('status', 'out_for_delivery')
            ->update([
                'rider_lat' => round((float) $data['lat'], 7),
                'rider_lng' => round((float) $data['lng'], 7),
                'rider_ts' => time(),
            ]);

        return response()->json(['status' => true, 'updated' => $updated]);
    }
}