<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AppliesOnlineOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Services\DeliveryOtpService;
use App\Services\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Delivery person ke liye (role = delivery, ya purane tareeke se is_delivery wala staff).
 * Wo sirf apne assigned orders dekh sakta hai, OTP se "delivered" ya "failed" kar sakta hai.
 * Cancel, baaki orders, ya POS ke koi aur API uske haath mein nahi (BlockDeliveryRole middleware bhi rokta hai).
 */
class DeliveryOrderController extends Controller
{
    use AppliesOnlineOrderStatus;

    /** Cancel hue order itni der tak delivery person ki screen par alert ki tarah dikhte hain */
    private const CANCELLED_VISIBLE_HOURS = 3;

    private function courier(Request $request)
    {
        $u = $request->user();
        abort_unless($u && ($u->role === 'delivery' || $u->is_delivery), 403, 'Only delivery staff can use this.');
        abort_unless($u->is_active, 403, 'Your account is not active.');
        abort_unless($u->store_id, 403, 'No store is assigned to this account.');

        // Store ke liye online store feature chalu ho (delivery role ke apne features nahi hote, isliye yahan check)
        abort_unless(
            in_array('online_store', FeatureService::storeFeatures((int) $u->store_id), true),
            403,
            'Online store is not enabled for this store.'
        );

        return $u;
    }

    private function mine($user)
    {
        return OnlineOrder::where('store_id', $user->store_id)->where('delivery_user_id', $user->id);
    }

    // GET /api/delivery/orders   (har 8-10 second mein poll hota hai: heartbeat + naye order ka alert)
    public function index(Request $request): JsonResponse
    {
        $user = $this->courier($request);

        // Heartbeat: POS ko dikhe ki iski screen khuli hai
        $user->forceFill(['delivery_last_seen_at' => now()])->save();

        $active = $this->mine($user)
            ->with('items')
            ->where('status', 'out_for_delivery')
            ->orderBy('assigned_at')
            ->get();

        $cancelled = $this->mine($user)
            ->with('items')
            ->where('status', 'cancelled')
            ->where('cancelled_at', '>=', now()->subHours(self::CANCELLED_VISIBLE_HOURS))
            ->orderByDesc('cancelled_at')
            ->get();

        $doneToday = $this->mine($user)->where('status', 'delivered')->whereDate('delivered_at', today());

        return response()->json([
            'status' => true,
            'data' => $active->map(fn (OnlineOrder $o) => $o->courierArray())->values(),
            'cancelled' => $cancelled->map(fn (OnlineOrder $o) => $o->courierArray())->values(),
            'done_today' => (clone $doneToday)->count(),
            'cash_today' => (float) (clone $doneToday)->sum('cod_collected'),
            'name' => $user->name,
            'on_duty' => (bool) $user->on_duty,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    // GET /api/delivery/history   -> aaj ke delivered orders + cash
    public function history(Request $request): JsonResponse
    {
        $user = $this->courier($request);

        $orders = $this->mine($user)
            ->with('items')
            ->where('status', 'delivered')
            ->whereDate('delivered_at', today())
            ->orderByDesc('delivered_at')
            ->limit(100)
            ->get();

        return response()->json([
            'status' => true,
            'data' => $orders->map(fn (OnlineOrder $o) => $o->courierArray())->values(),
            'count' => $orders->count(),
            'cash_total' => (float) $orders->sum('cod_collected'),
        ]);
    }

    // POST /api/delivery/duty   { on_duty: true|false }
    public function duty(Request $request): JsonResponse
    {
        $user = $this->courier($request);
        $data = $request->validate(['on_duty' => ['required', 'boolean']]);
        $on = (bool) $data['on_duty'];

        if (! $on) {
            $busy = $this->mine($user)->where('status', 'out_for_delivery')->count();
            if ($busy > 0) {
                return $this->fail("You still have {$busy} order(s) out for delivery. Finish or return them first.", 409);
            }
        }

        $user->forceFill(['on_duty' => $on, 'delivery_last_seen_at' => now()])->save();

        return response()->json([
            'status' => true,
            'on_duty' => $on,
            'message' => $on ? 'You are on duty.' : 'You are off duty.',
        ]);
    }

    // POST /api/delivery/orders/{id}/resend-otp
    public function resendOtp(Request $request, int $id): JsonResponse
    {
        $user = $this->courier($request);
        $order = $this->mine($user)->findOrFail($id);

        $res = app(DeliveryOtpService::class)->resend($order);
        $body = ['status' => $res['ok'], 'message' => $res['message'], 'retry_after' => $res['retry_after']];

        return response()->json($body, $res['ok'] ? 200 : $res['code']);
    }

    // POST /api/delivery/orders/{id}/delivered   { otp }
    public function delivered(Request $request, int $id): JsonResponse
    {
        $user = $this->courier($request);

        $data = $request->validate([
            'otp' => ['required', 'digits:'.DeliveryOtpService::LENGTH],
        ], [
            'otp.required' => 'Enter the OTP told by the customer.',
            'otp.digits' => 'OTP must be '.DeliveryOtpService::LENGTH.' digits.',
        ]);

        $order = $this->mine($user)->with('items')->findOrFail($id);

        // Dobara tap / double submit: bill ya stock dobara na kate
        if ($order->status === 'delivered') {
            return response()->json(['status' => true, 'data' => $order->courierArray()]);
        }
        if ($order->status === 'cancelled') {
            return $this->fail('This order was cancelled by the store.', 409);
        }
        if ($order->status !== 'out_for_delivery') {
            return $this->fail("Order is {$order->status}.", 409);
        }

        $check = app(DeliveryOtpService::class)->verify($order, $data['otp']);
        if (! $check['ok']) {
            return response()->json(array_filter([
                'status' => false,
                'message' => $check['message'],
                'attempts_left' => $check['attempts_left'] ?? null,
                'locked_until' => $check['locked_until'] ?? null,
            ], fn ($v) => $v !== null), $check['code']);
        }

        $err = $this->applyOnlineStatus($order, 'delivered', $user, null, ['otp_verified' => true]);
        if ($err) {
            return $this->fail($err['message'], $err['code']);
        }

        return response()->json(['status' => true, 'data' => $order->fresh(['items'])->courierArray()]);
    }

    // POST /api/delivery/orders/{id}/failed   { reason }
    // Order wapas "ready" ho jata hai, store dobara assign / cancel kar sakta hai.
    public function failed(Request $request, int $id): JsonResponse
    {
        $user = $this->courier($request);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        $order = $this->mine($user)->findOrFail($id);

        $conflict = DB::transaction(function () use ($order, $user, $data) {
            $fresh = OnlineOrder::lockForUpdate()->find($order->id);
            if ($fresh->status !== 'out_for_delivery' || (int) $fresh->delivery_user_id !== (int) $user->id) {
                return "Order is {$fresh->status}.";
            }

            $fresh->update(['status' => 'ready', 'delivery_user_id' => null, 'assigned_at' => null]);
            $fresh->events()->create([
                'status' => 'ready',
                'actor' => 'staff',
                'user_id' => $user->id,
                'note' => "Delivery failed ({$user->name}): {$data['reason']}",
            ]);

            return null;
        });

        if ($conflict) {
            return $this->fail($conflict, 409);
        }

        return response()->json(['status' => true, 'message' => 'Order sent back to the store.']);
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => false, 'message' => $message], $status);
    }
}