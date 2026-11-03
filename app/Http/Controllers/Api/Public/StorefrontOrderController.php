<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Models\StorefrontAddress;
use App\Models\StorefrontCustomer;
use App\Services\StorefrontCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Online order place karna. Server customer ki kisi baat par bharosa nahi karta:
 * price, stock, delivery fee, minimum order, pincode aur store ka time sab yahin dobara check hota hai.
 * Abhi sirf Cash on delivery / Pay at store. Stock POS mein tab katega jab order deliver hoga (Step E),
 * tab tak order ka saaman "reserved" maana jata hai (StorefrontCatalog::reservedMap).
 */
class StorefrontOrderController extends Controller
{
    // POST /api/public/orders   (header: Idempotency-Key)
    public function store(Request $request): JsonResponse
    {
        /** @var StorefrontCustomer $customer */
        $customer = $request->attributes->get('storefront_customer');

        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $key)) {
            return $this->fail('Missing or invalid Idempotency-Key.', 400);
        }

        $data = $request->validate([
            'store' => ['required', 'string', 'max:150'],
            'mode' => ['required', 'in:delivery,pickup'],
            'address_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:200'],
            'expected_total' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:999'],
        ]);

        // Dobara aayi hui same request: purana order hi wapas
        $existing = OnlineOrder::with('items')
            ->where('storefront_customer_id', $customer->id)
            ->where('idempotency_key', $key)
            ->first();
        if ($existing) {
            return response()->json(['status' => true, 'data' => $this->format($existing)]);
        }

        $front = app(StorefrontController::class);
        $listing = $front->findStore($data['store']); // slug galat ho to 404

        if (! $listing->fulfil_branch_id) {
            return $this->fail('This store is not accepting online orders right now.', 422);
        }
        if (! $front->isOpenNow($listing->open_time, $listing->close_time)) {
            return $this->fail("{$listing->display_name} is closed right now.", 422);
        }

        $isDelivery = $data['mode'] === 'delivery';
        if ($isDelivery && ! $listing->accepts_delivery) {
            return $this->fail('This store does not offer delivery.', 422);
        }
        if (! $isDelivery && ! $listing->accepts_pickup) {
            return $this->fail('This store does not offer pickup.', 422);
        }

        // Delivery: address customer ka apna ho, aur store us pincode par deliver karta ho
        $address = null;
        if ($isDelivery) {
            $address = StorefrontAddress::where('storefront_customer_id', $customer->id)
                ->find($data['address_id'] ?? 0);
            if (! $address) {
                return $this->fail('Please choose a delivery address.', 422);
            }

            $pin = DB::table('store_service_pincodes')
                ->where('store_id', $listing->store_id)
                ->where('pincode', $address->pincode)
                ->where('is_active', true)
                ->first();
            if (! $pin) {
                return $this->fail("{$listing->display_name} does not deliver to {$address->pincode}.", 422);
            }

            $listing->pin_delivery_charge = $pin->delivery_charge;
            $listing->pin_min_order = $pin->min_order_amount;
        }

        $name = trim((string) ($isDelivery ? $address->name : ($data['name'] ?? $customer->name ?? '')));
        if (mb_strlen($name) < 2) {
            return $this->fail('Please enter your name.', 422);
        }

        // Ek hi product do baar aaya ho to qty jod do
        $wanted = [];
        foreach ($data['items'] as $row) {
            $id = (int) $row['product_id'];
            $wanted[$id] = round(($wanted[$id] ?? 0) + (float) $row['qty'], 2);
        }

        $terms = $front->formatStore($listing);
        $catalog = app(StorefrontCatalog::class);

        $result = $this->lockedTransaction(function () use (
            $customer, $key, $data, $listing, $terms, $catalog, $wanted, $isDelivery, $address, $name
        ) {
            // Is store ke orders ek ek karke: warna do customers ek hi bache hue stock ke liye aage-peeche nikal jayenge
            // OVERSELLING SE BACHAV (tatkal jaisa): is store ke orders ek ek karke chalte hain. Jab tak ek order
            // commit nahi hota, doosra yahin ruka rehta hai, aur jab chalta hai to ghata hua stock hi dekhta hai
            // (sellables() = online stock - pending orders ka reserved). Isliye 2 bache aur 2 customers ne 2-2 maange
            // to pehle wale ko order milta hai, doosre ko 409 STOCK. Ye transaction ka PEHLA statement hona chahiye:
            // MySQL ka snapshot pehle normal SELECT par banta hai, lock se pehle select kiya to purana stock dikhega.
            DB::table('stores')->where('id', $listing->store_id)->lockForUpdate()->first();

            $existing = OnlineOrder::with('items')
                ->where('storefront_customer_id', $customer->id)
                ->where('idempotency_key', $key)
                ->first();
            if ($existing) {
                return $existing;
            }

            $live = $catalog->sellables($listing, array_keys($wanted));

            $lines = [];
            $problems = false;
            $subtotal = 0.0;
            $saved = 0.0;

            foreach ($wanted as $id => $qty) {
                $p = $live[$id] ?? null;

                if (! $p || $p['price'] === null || $p['price'] <= 0 || $p['available'] + 1e-9 < $qty) {
                    $problems = true;
                    continue;
                }
                if ($p['is_unit'] && abs($qty - round($qty)) > 1e-9) {
                    return $this->fail("{$p['name']} can only be ordered in whole numbers.", 422);
                }

                $amount = round($p['price'] * $qty, 2);
                $subtotal += $amount;
                if ($p['is_unit'] && $p['mrp'] && $p['mrp'] > $p['price']) {
                    $saved += ($p['mrp'] - $p['price']) * $qty;
                }
                $lines[] = ['p' => $p, 'qty' => $qty, 'amount' => $amount];
            }

            // Stock kam / item hat gaya: cart ko theek karne ke liye taaza price + stock wapas bhejte hain
            if ($problems) {
                return $this->conflict('STOCK', 'Some items are not available in the quantity you chose (low stock or order limit). Your cart has been updated.', $wanted, $live);
            }

            $subtotal = round($subtotal, 2);

            $minOrder = (float) $terms['min_order_amount'];
            if ($isDelivery && $subtotal + 1e-9 < $minOrder) {
                return $this->fail('Minimum order for delivery is ₹'.rtrim(rtrim(number_format($minOrder, 2, '.', ''), '0'), '.').'.', 422);
            }

            $freeAbove = $terms['free_delivery_above'];
            $fee = 0.0;
            if ($isDelivery) {
                $free = $freeAbove !== null && $freeAbove > 0 && $subtotal >= $freeAbove;
                $fee = $free ? 0.0 : (float) $terms['delivery_charge'];
            }
            $total = round($subtotal + $fee, 2);

            // Customer ne jo total dekha tha wahi na ho to pehle bata do (price badal gaya)
            if (isset($data['expected_total']) && abs((float) $data['expected_total'] - $total) > 0.011) {
                return $this->conflict('PRICE_CHANGED', 'Prices or charges have changed. Please check your cart.', $wanted, $live, $total);
            }

            $order = OnlineOrder::create([
                'order_no' => Str::random(16), // neeche id ke hisaab se pakka number lagta hai
                'storefront_customer_id' => $customer->id,
                'store_id' => $listing->store_id,
                'branch_id' => $listing->fulfil_branch_id,
                'store_name' => $listing->display_name,
                'store_slug' => $listing->slug,
                'store_phone' => $listing->phone ? Str::limit($listing->phone, 20, '') : null,
                'store_address' => $listing->address ? Str::limit($listing->address, 250, '') : null,
                'mode' => $data['mode'],
                'status' => 'placed',
                'payment_method' => $isDelivery ? 'cod' : 'pay_at_store',
                'payment_status' => 'pending',
                'subtotal' => $subtotal,
                'delivery_fee' => $fee,
                'total' => $total,
                'total_saved' => round($saved, 2),
                'customer_name' => $name,
                'customer_mobile' => $customer->mobile,
                'addr_label' => $address?->label,
                'addr_name' => $address?->name,
                'addr_mobile' => $address?->mobile,
                'addr_line1' => $address?->line1,
                'addr_line2' => $address?->line2,
                'addr_landmark' => $address?->landmark,
                'addr_city' => $address?->city,
                'addr_pincode' => $address?->pincode,
                'note' => isset($data['note']) ? trim($data['note']) ?: null : null,
                'idempotency_key' => $key,
                'placed_at' => now(),
            ]);

            $order->update(['order_no' => 'VK'.now()->format('ymd').str_pad((string) $order->id, 5, '0', STR_PAD_LEFT)]);

            foreach ($lines as $l) {
                $order->items()->create([
                    'product_id' => $l['p']['id'],
                    'name' => $l['p']['name'],
                    'price_unit' => $l['p']['price_unit'],
                    'qty' => $l['qty'],
                    'price' => $l['p']['price'],
                    'mrp' => $l['p']['mrp'],
                    'amount' => $l['amount'],
                ]);
            }

            $order->events()->create(['status' => 'placed', 'actor' => 'customer']);

            if (! $customer->name) {
                $customer->update(['name' => $name]);
            }

            return $order->load('items', 'events');
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => $this->format($result)], 201);
    }

    // GET /api/public/orders?page=   (mere orders, naya pehle)
    public function index(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $page = OnlineOrder::with('items')
            ->where('storefront_customer_id', $customer->id)
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json([
            'status' => true,
            'data' => $page->getCollection()->map(fn (OnlineOrder $o) => $o->summaryArray())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    // POST /api/public/orders/{orderNo}/cancel   (sirf tab jab store ne order accept nahi kiya)
    public function cancel(Request $request, string $orderNo): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $error = null;
        $order = null;

        DB::transaction(function () use ($customer, $orderNo, &$error, &$order) {
            $order = OnlineOrder::where('storefront_customer_id', $customer->id)
                ->where('order_no', $orderNo)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                $error = ['Order not found.', 404];

                return;
            }
            if ($order->status !== 'placed') {
                $error = $order->status === 'cancelled'
                    ? ['This order is already cancelled.', 409]
                    : ['The store has already started on this order. Please call the store to cancel.', 409];

                return;
            }

            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => 'Cancelled by customer',
            ]);
            $order->events()->create(['status' => 'cancelled', 'actor' => 'customer', 'note' => 'Cancelled by customer']);
        });

        if ($error) {
            return $this->fail($error[0], $error[1]);
        }

        return response()->json(['status' => true, 'data' => $order->fresh(['items', 'events'])->apiArray()]);
    }

    // GET /api/public/orders/{orderNo}
    public function show(Request $request, string $orderNo): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $order = OnlineOrder::with('items')
            ->where('storefront_customer_id', $customer->id)
            ->where('order_no', $orderNo)
            ->first();

        if (! $order) {
            return $this->fail('Order not found.', 404);
        }

        return response()->json(['status' => true, 'data' => $this->format($order)]);
    }

    /**
     * Transaction chalao. Deadlock par 3 baar khud dobara koshish. Lock ka wait lamba ho jaye (store par bahut
     * orders ek saath) to customer ko 50 sec latka kar 500 dene ki jagah 10 sec baad 503 "thodi der baad try karo".
     *
     * @return mixed  closure ka return, ya busy hone par JsonResponse
     */
    private function lockedTransaction(\Closure $work)
    {
        try {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
            }

            return DB::transaction($work, 3);
        } catch (QueryException $e) {
            // MySQL: 1205 = lock wait timeout, 1213 = deadlock
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                return response()->json([
                    'status' => false,
                    'code' => 'BUSY',
                    'message' => 'The store is handling a lot of orders right now. Please try again in a moment.',
                ], 503)->header('Retry-After', '2');
            }

            throw $e;
        }
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => false, 'message' => $message], $status);
    }

    // 409: frontend ye items se cart ka price / stock theek kar leta hai
    private function conflict(string $code, string $message, array $wanted, array $live, ?float $total = null): JsonResponse
    {
        $items = [];
        foreach ($wanted as $id => $qty) {
            $p = $live[$id] ?? null;
            $items[] = [
                'id' => $id,
                'name' => $p['name'] ?? 'Item',
                'price' => $p['price'] ?? 0,
                'mrp' => $p['mrp'] ?? null,
                'available' => $p['available'] ?? 0,
            ];
        }

        return response()->json([
            'status' => false,
            'code' => $code,
            'message' => $message,
            'total' => $total,
            'items' => $items,
        ], 409);
    }

    private function format(OnlineOrder $o): array
    {
        return $o->apiArray();
    }
}