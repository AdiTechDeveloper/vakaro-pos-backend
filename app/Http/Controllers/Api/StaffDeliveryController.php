<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesOnlineScope;
use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin Staff page se kisi staff ko "Can deliver" on / off karta hai.
 */
class StaffDeliveryController extends Controller
{
    use ResolvesOnlineScope;

    // POST /api/staff/{id}/delivery   { is_delivery: true|false }
    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->role === 'admin', 403, 'Only the admin can change this.');

        [, $branchIds] = $this->onlineScope($request);
        $data = $request->validate(['is_delivery' => ['sometimes', 'boolean']]);

        // Sirf apne store ki branches ka staff (ya admin khud)
        $inMyBranches = DB::table('branch_staff')
            ->where('user_id', $id)
            ->whereIn('branch_id', $branchIds)
            ->exists();
        abort_unless($inMyBranches || $id === (int) $actor->id, 404, 'Staff not found.');

        $user = User::findOrFail($id);
        $turnOn = (bool) $data['is_delivery'];

        // Band karne se pehle: uske chalte hue delivery orders kisi aur ko do
        if (! $turnOn && $user->role !== 'delivery') {
            $active = OnlineOrder::where('delivery_user_id', $user->id)->where('status', 'out_for_delivery')->exists();
            if ($active) {
                return response()->json([
                    'status' => false,
                    'message' => 'This person has orders out for delivery. Assign them to someone else first.',
                ], 422);
            }
        }

        $user->is_delivery = $turnOn;
        $user->save();

        return response()->json([
            'status' => true,
            'message' => $turnOn ? 'Can deliver turned on.' : 'Can deliver turned off.',
            'data' => ['id' => $user->id, 'is_delivery' => (bool) $user->is_delivery],
        ]);
    }
}