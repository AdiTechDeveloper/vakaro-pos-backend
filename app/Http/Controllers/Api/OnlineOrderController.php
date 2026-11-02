<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AppliesOnlineOrderStatus;
use App\Http\Controllers\Concerns\ResolvesOnlineScope;
use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Models\User;
use App\Services\DeliveryOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store ke staff ke liye online orders (Sanctum login wale POS user).
 * Branch-wise rok: admin ko poore store ke orders, manager/cashier ko sirf apni branch ke
 * (ResolvesOnlineScope se).
 */
class OnlineOrderController extends Controller
{
    use ResolvesOnlineScope;
    use AppliesOnlineOrderStatus;

    /** Ek delivery person ke paas ek saath itne orders ho sakte hain */
    private const MAX_ACTIVE_PER_COURIER = 5;

    /** Itne minute ke andar delivery screen se poll aaya ho to "online" maano */
    private const ONLINE_WITHIN_MINUTES = 3;

    // GET /api/online-orders?status=active|history|placed,accepted&branch_id=&per_page=&page=
    public function index(Request $request): JsonResponse
    {
        [$storeId, $branchIds] = $this->onlineScope($request);

        $q = OnlineOrder::query()
            ->where('store_id', $storeId)
            ->whereIn('branch_id', $branchIds)
            ->with('items', 'events');

        $status = (string) $request->query('status', 'active');
        if ($status === 'active') {
            $q->whereIn('status', OnlineOrder::PENDING_STATUSES);
        } elseif ($status === 'history') {
            $q->whereIn('status', ['delivered', 'cancelled']);
        } elseif ($status !== 'all' && $status !== '') {
            $q->whereIn('status', array_intersect(explode(',', $status), array_keys(OnlineOrder::TRANSITIONS)));
        }

        if ($branch = (int) $request->query('branch_id')) {
            abort_unless(in_array($branch, $branchIds, true), 403, 'Invalid branch.');
            $q->where('branch_id', $branch);
        }

        $perPage = max(1, min((int) $request->query('per_page', 20), 50));
        $page = $q->orderByDesc('id')->paginate($perPage);

        // Naye order ka alert: frontend har 15-20 second mein ye call karke latest_id / counts.placed dekhe
        $scoped = fn () => OnlineOrder::where('store_id', $storeId)->whereIn('branch_id', $branchIds);

        $counts = $scoped()
            ->whereIn('status', OnlineOrder::PENDING_STATUSES)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as c')
            ->pluck('c', 'status');

        return response()->json([
            'status' => true,
            'data' => $page->getCollection()->map(fn (OnlineOrder $o) => $o->apiArray(true))->values(),
            'counts' => $counts,
            'latest_id' => (int) $scoped()->max('id'),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    // GET /api/online-orders/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        [$storeId, $branchIds] = $this->onlineScope($request);

        $order = OnlineOrder::where('store_id', $storeId)->whereIn('branch_id', $branchIds)->findOrFail($id);

        return response()->json(['status' => true, 'data' => $order->apiArray(true)]);
    }

    // POST /api/online-orders/{id}/status   { status, reason? }
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        [$storeId, $branchIds] = $this->onlineScope($request);

        $data = $request->validate([
            'status' => ['required', 'in:accepted,preparing,ready,out_for_delivery,delivered,cancelled'],
            'reason' => ['nullable', 'string', 'max:200'],
            // Delivery order ko OTP ke bina deliver karna (sirf admin / manager, reason zaroori)
            'override' => ['nullable', 'boolean'],
        ]);

        $order = OnlineOrder::with('items')
            ->where('store_id', $storeId)
            ->whereIn('branch_id', $branchIds)
            ->findOrFail($id);

        $override = (bool) ($data['override'] ?? false);
        if ($override && ! in_array($request->user()->role, ['admin', 'manager'], true)) {
            return $this->fail('Only the admin or a manager can deliver without the customer OTP.', 403);
        }

        $err = $this->applyOnlineStatus($order, $data['status'], $request->user(), $data['reason'] ?? null, ['override' => $override]);
        if ($err) {
            return $this->fail($err['message'], $err['code']);
        }

        return response()->json(['status' => true, 'data' => $order->fresh(['items', 'events'])->apiArray(true)]);
    }

    // GET /api/online-orders/delivery-staff  -> assign dropdown ke liye (branch-wise)
    // Delivery person = role "delivery" wala user, ya koi staff jiska is_delivery on hai.
    // Branch = branch_staff table. Admin (jiski branch_staff row nahi) khud ko sab branches ke liye assign kar sakta hai.
    public function deliveryStaff(Request $request): JsonResponse
    {
        [, $branchIds] = $this->onlineScope($request);
        $me = $request->user();

        $links = DB::table('branch_staff')
            ->whereIn('branch_id', $branchIds)
            ->get(['user_id', 'branch_id'])
            ->groupBy('user_id');

        $staff = User::where('is_delivery', true)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('id', $links->keys()->all())->orWhere('id', $me->id))
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'on_duty', 'delivery_last_seen_at']);

        $active = OnlineOrder::whereIn('delivery_user_id', $staff->pluck('id'))
            ->where('status', 'out_for_delivery')
            ->groupBy('delivery_user_id')
            ->selectRaw('delivery_user_id, COUNT(*) as c')
            ->pluck('c', 'delivery_user_id');

        $data = $staff->map(function ($u) use ($links, $branchIds, $active) {
            $seen = $u->delivery_last_seen_at ? \Illuminate\Support\Carbon::parse($u->delivery_last_seen_at) : null;

            return [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role,
                'on_duty' => (bool) $u->on_duty,
                // On duty hai aur app haal hi mein khuli thi
                'app_online' => (bool) $u->on_duty && $seen && $seen->gt(now()->subMinutes(self::ONLINE_WITHIN_MINUTES)),
                'last_seen_at' => $seen?->toIso8601String(),
                'active_orders' => (int) ($active[$u->id] ?? 0),
                'max_active' => self::MAX_ACTIVE_PER_COURIER,
                'branch_ids' => $links->has($u->id)
                    ? $links[$u->id]->pluck('branch_id')->map(fn ($b) => (int) $b)->values()->all()
                    : array_map('intval', $branchIds),
            ];
        })->values();

        return response()->json(['status' => true, 'data' => $data]);
    }

    // POST /api/online-orders/{id}/assign   { delivery_user_id }
    // Ready order ko delivery person ko do -> order "out_for_delivery" ho jata hai aur customer ko OTP SMS jata hai.
    // Pehle se out_for_delivery ho to sirf delivery person badalta hai (OTP wahi rehta hai jo customer ke paas hai).
    public function assign(Request $request, int $id): JsonResponse
    {
        [$storeId, $branchIds] = $this->onlineScope($request);
        $actor = $request->user();

        $data = $request->validate(['delivery_user_id' => ['required', 'integer']]);

        $order = OnlineOrder::where('store_id', $storeId)->whereIn('branch_id', $branchIds)->findOrFail($id);

        if ($order->mode !== 'delivery') {
            return $this->fail('This is a pickup order.', 422);
        }

        // Delivery person isi order ki branch ka hona chahiye (ya admin khud)
        $courier = User::where('is_delivery', true)
            ->where('is_active', true)
            ->where(function ($q) use ($order, $actor) {
                $q->whereIn('id', DB::table('branch_staff')->where('branch_id', $order->branch_id)->select('user_id'))
                    ->orWhere('id', $actor->id);
            })
            ->find($data['delivery_user_id']);
        if (! $courier) {
            return $this->fail('Choose an active delivery person of this branch.', 422);
        }
        if (! $courier->on_duty) {
            return $this->fail("{$courier->name} is off duty. Ask them to go on duty, or choose someone else.", 422);
        }

        $alreadyHis = (int) $order->delivery_user_id === (int) $courier->id && $order->status === 'out_for_delivery';
        if (! $alreadyHis) {
            $load = OnlineOrder::where('delivery_user_id', $courier->id)->where('status', 'out_for_delivery')->count();
            if ($load >= self::MAX_ACTIVE_PER_COURIER) {
                return $this->fail("{$courier->name} already has {$load} orders. Choose someone else.", 422);
            }
        }

        $firstDispatch = false;
        $noChange = false;
        $conflict = DB::transaction(function () use ($order, $courier, $actor, &$firstDispatch, &$noChange) {
            $fresh = OnlineOrder::lockForUpdate()->find($order->id);
            if (! in_array($fresh->status, ['ready', 'out_for_delivery'], true)) {
                return "Order is {$fresh->status}. Mark it ready before assigning a delivery person.";
            }

            $previous = $fresh->status === 'out_for_delivery' ? $fresh->deliveryUser?->name : null;
            if ($fresh->status === 'out_for_delivery' && (int) $fresh->delivery_user_id === (int) $courier->id) {
                $noChange = true; // wahi delivery person, kuch badalna nahi

                return null;
            }

            $firstDispatch = $fresh->status === 'ready';

            $fresh->update([
                'status' => 'out_for_delivery',
                'delivery_user_id' => $courier->id,
                'assigned_at' => now(),
            ]);

            $fresh->events()->create([
                'status' => 'out_for_delivery',
                'actor' => 'staff',
                'user_id' => $actor->id,
                'note' => $previous ? "Reassigned from {$previous} to {$courier->name}" : "Assigned to {$courier->name}",
            ]);

            return null;
        });

        if ($conflict) {
            return $this->fail($conflict, 409);
        }

        $order = $order->fresh(['items', 'events']);
        $otp = app(DeliveryOtpService::class);
        $smsSent = null;

        if ($order->status === 'out_for_delivery' && ! $noChange) {
            if (empty($order->delivery_otp_enc) || $firstDispatch) {
                $smsSent = $otp->issue($order);
            } else {
                $otp->resetAttempts($order); // naya delivery person, OTP wahi, attempts naye
            }
            $order = $order->fresh(['items', 'events']);
        }

        return response()->json([
            'status' => true,
            'data' => $order->apiArray(true),
            // false = SMS nahi gaya: POS par warning dikhao (customer apne order page par OTP dekh sakta hai)
            'otp_sms_sent' => $smsSent,
        ]);
    }

    // POST /api/online-orders/{id}/unassign   { reason }
    // Delivery person raste mein nahi mil raha / offline: order wapas "ready", dobara assign ho sakta hai.
    public function unassign(Request $request, int $id): JsonResponse
    {
        [$storeId, $branchIds] = $this->onlineScope($request);
        $actor = $request->user();

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        $order = OnlineOrder::where('store_id', $storeId)->whereIn('branch_id', $branchIds)->findOrFail($id);

        $conflict = DB::transaction(function () use ($order, $actor, $data) {
            $fresh = OnlineOrder::lockForUpdate()->find($order->id);
            if ($fresh->status !== 'out_for_delivery') {
                return "Order is {$fresh->status}.";
            }
            $name = $fresh->deliveryUser?->name ?? 'delivery person';

            $fresh->update(['status' => 'ready', 'delivery_user_id' => null, 'assigned_at' => null]);
            $fresh->events()->create([
                'status' => 'ready',
                'actor' => 'staff',
                'user_id' => $actor->id,
                'note' => "Taken back from {$name}: {$data['reason']}",
            ]);

            return null;
        });

        if ($conflict) {
            return $this->fail($conflict, 409);
        }

        return response()->json(['status' => true, 'data' => $order->fresh(['items', 'events'])->apiArray(true)]);
    }

    // POST /api/online-orders/{id}/resend-otp  -> customer ko delivery OTP SMS dobara
    public function resendOtp(Request $request, int $id): JsonResponse
    {
        [$storeId, $branchIds] = $this->onlineScope($request);

        $order = OnlineOrder::where('store_id', $storeId)->whereIn('branch_id', $branchIds)->findOrFail($id);

        $res = app(DeliveryOtpService::class)->resend($order);

        return response()->json(
            ['status' => $res['ok'], 'message' => $res['message'], 'retry_after' => $res['retry_after']],
            $res['ok'] ? 200 : $res['code']
        );
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => false, 'message' => $message], $status);
    }
}