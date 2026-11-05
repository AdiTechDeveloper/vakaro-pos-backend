<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class OnlineOrder extends Model
{
    /** Jin orders ka saaman abhi store ke paas "ruka hua" hai (stock se ghataya jata hai). */
    public const PENDING_STATUSES = ['placed', 'accepted', 'preparing', 'ready', 'out_for_delivery'];

    /** Status aage kahan ja sakta hai. Pickup order "out_for_delivery" nahi jata (controller mein roka jata hai). */
    public const TRANSITIONS = [
        'placed' => ['accepted', 'cancelled'],
        'accepted' => ['preparing', 'ready', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['out_for_delivery', 'delivered', 'cancelled'],
        'out_for_delivery' => ['delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    protected $guarded = [];

    // OTP kabhi bhi toArray()/JSON mein na jaye
    protected $hidden = ['delivery_otp_enc'];

    protected $casts = [
        'placed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'delivered_at' => 'datetime',
        'assigned_at' => 'datetime',
        'delivery_otp_sent_at' => 'datetime',
        'delivery_otp_locked_until' => 'datetime',
        'delivery_otp_overridden' => 'boolean',
        'cod_collected' => 'float',
        'subtotal' => 'float',
        'delivery_fee' => 'float',
        'total' => 'float',
        'total_saved' => 'float',
        'addr_lat' => 'float',
        'addr_lng' => 'float',
        'eta_ts' => 'integer',
        'rider_lat' => 'float',
        'rider_lng' => 'float',
        'rider_ts' => 'integer',
    ];

    /** Raste ka andaza: seedhi doori ko 1.35 se guna karke 25 km/h ki speed se. Baad mein admin setting bana sakte hain. */
    public const ROAD_FACTOR = 1.35;
    public const SPEED_KMPH = 25;

    /** Store sabse zyada kitne din aage ka time de sakta hai. */
    public const MAX_EXTEND_DAYS = 7;

    protected static function booted(): void
    {
        // Delivery wala order raste mein nikla: ab sirf raste ka time bacha hai.
        // Ye yahin model mein hai, isliye staff ka status badalne wala code chhedna nahi padta.
        static::updating(function (OnlineOrder $order) {
            if ($order->isDirty('status')
                && $order->status === 'out_for_delivery'
                && $order->mode === 'delivery') {
                $order->eta_ts = time() + ((int) $order->travel_minutes) * 60;
                $order->eta_note = null; // "store ne time badhaya" wali baat ab purani ho gayi
            }

            // Order raste se hat gaya (deliver / cancel / wapas store): delivery wale ki location mita do
            if ($order->isDirty('status') && $order->status !== 'out_for_delivery') {
                $order->rider_lat = null;
                $order->rider_lng = null;
                $order->rider_ts = null;
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OnlineOrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OnlineOrderEvent::class)->orderBy('id');
    }

    /**
     * Order kab tak pahunchega (unix seconds se, timezone ka koi asar nahi).
     * Order khatam ho gaya, ya pickup order ready hai, to koi time nahi.
     */
    public function etaAt(): ?Carbon
    {
        if (! $this->eta_ts || ! in_array($this->status, self::PENDING_STATUSES, true)) {
            return null;
        }
        if ($this->mode !== 'delivery' && $this->status === 'ready') {
            return null;
        }

        return Carbon::createFromTimestamp((int) $this->eta_ts);
    }

    public function etaMinutes(): ?int
    {
        $eta = $this->etaAt();

        return $eta ? max(0, (int) ceil(($eta->getTimestamp() - time()) / 60)) : null;
    }

    /**
     * Customer ke exact pin par Google Maps. Pin na ho (address haath se likha tha) to
     * address ke text se search, taaki delivery wale ke paas hamesha kuch na kuch ho.
     */
    public function mapsUrl(): ?string
    {
        if ($this->addr_lat && $this->addr_lng) {
            return 'https://www.google.com/maps/dir/?api=1&destination='.$this->addr_lat.','.$this->addr_lng;
        }

        $text = trim(implode(', ', array_filter([
            $this->addr_line1, $this->addr_line2, $this->addr_landmark, $this->addr_city, $this->addr_pincode,
        ])));

        return $text !== '' ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($text) : null;
    }

    /**
     * Store delivery ka time badhata hai (Swiggy / Zomato jaisa). Order raste mein jaane se pehle hi.
     *  - $addMinutes: abhi ke time me itne minute jodo ("+30 min")
     *  - $untilTs:    seedha ye time (unix seconds), jaise kal subah 10 baje
     * Authorization (ye order is staff ka hai ya nahi) calling controller me check karo, jaise accept me hota hai.
     *
     * @throws \DomainException  galat status / galat time
     */
    public function extendEta(?int $addMinutes, ?int $untilTs = null, ?string $note = null): void
    {
        if (! in_array($this->status, ['placed', 'accepted', 'preparing', 'ready'], true)) {
            throw new \DomainException('The delivery time can only be changed before the order goes out for delivery.');
        }

        $now = time();

        if ($untilTs) {
            $target = $untilTs;
        } elseif ($addMinutes) {
            $target = max((int) $this->eta_ts, $now) + $addMinutes * 60;
        } else {
            return; // koi badlav nahi: default time hi rahega
        }

        if ($target <= $now) {
            throw new \DomainException('Please choose a time in the future.');
        }
        if ($target > $now + self::MAX_EXTEND_DAYS * 86400) {
            throw new \DomainException('The delivery time cannot be more than '.self::MAX_EXTEND_DAYS.' days away.');
        }

        $note = trim((string) $note);

        $this->eta_ts = $target;
        $this->eta_note = $note !== '' ? mb_substr($note, 0, 200) : 'The store has updated your delivery time.';
        $this->save();

        $this->events()->create([
            'status' => $this->status,
            'actor' => 'staff',
            'note' => 'Delivery time changed by the store. '.$this->eta_note,
        ]);
    }

    /** Delivery wale se customer ke pin tak seedhi doori (km). Dono jagah ki location ho tabhi. */
    public function distanceToCustomerKm(): ?float
    {
        if (! $this->addr_lat || ! $this->addr_lng || ! $this->rider_lat || ! $this->rider_lng) {
            return null;
        }

        $earth = 6371.0;
        $dLat = deg2rad($this->addr_lat - $this->rider_lat);
        $dLng = deg2rad($this->addr_lng - $this->rider_lng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->rider_lat)) * cos(deg2rad($this->addr_lat)) * sin($dLng / 2) ** 2;

        return round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    /** Delivery wale ki abhi ki jagah se customer tak kitne minute lagenge (andaza). */
    public function liveEtaMinutes(): ?int
    {
        $km = $this->distanceToCustomerKm();

        return $km === null
            ? null
            : max(1, (int) ceil(($km * self::ROAD_FACTOR / self::SPEED_KMPH) * 60));
    }

    /** Poora order (storefront aur staff dono ke liye). $staff = true par andar ki ids bhi. */
    public function apiArray(bool $staff = false): array
    {
        $this->loadMissing('items', 'events');

        $data = [
            'order_no' => $this->order_no,
            'status' => $this->status,
            'mode' => $this->mode,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'store' => [
                'name' => $this->store_name,
                'slug' => $this->store_slug,
                'phone' => $this->store_phone,
                'address' => $this->store_address,
            ],
            'customer_name' => $this->customer_name,
            'customer_mobile' => $this->customer_mobile,
            'address' => $this->mode === 'delivery' ? [
                'label' => $this->addr_label,
                'name' => $this->addr_name,
                'mobile' => $this->addr_mobile,
                'line1' => $this->addr_line1,
                'line2' => $this->addr_line2,
                'landmark' => $this->addr_landmark,
                'city' => $this->addr_city,
                'pincode' => $this->addr_pincode,
                'lat' => $this->addr_lat,
                'lng' => $this->addr_lng,
                'has_pin' => (bool) ($this->addr_lat && $this->addr_lng),
                'maps_url' => $this->mapsUrl(),
            ] : null,
            'note' => $this->note,
            'subtotal' => $this->subtotal,
            'delivery_fee' => $this->delivery_fee,
            'total' => $this->total,
            'total_saved' => $this->total_saved,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'eta_at' => $this->etaAt()?->toIso8601String(),
            'eta_minutes' => $this->etaMinutes(),
            'eta_note' => $this->eta_note,
            'items' => $this->items->map(fn($i) => [
                'product_id' => $i->product_id,
                'name' => $i->name,
                'price_unit' => $i->price_unit,
                'qty' => $i->qty,
                'price' => $i->price,
                'mrp' => $i->mrp,
                'amount' => $i->amount,
            ])->values(),
            'history' => $this->events->map(fn($e) => [
                'status' => $e->status,
                'actor' => $e->actor,
                'note' => $e->note,
                'at' => $e->created_at?->toIso8601String(),
            ])->values(),
            'delivery_user_id' => $this->delivery_user_id,
            'delivery_name'    => $this->deliveryUser?->name,
            'assigned_at'      => $this->assigned_at?->toIso8601String(),
        ];

        if (! $staff) {
            // Sirf customer (storefront) ko: delivery OTP, jab order raste mein ho (SMS na mile to yahin dikhega)
            $data['delivery_otp'] = ($this->status === 'out_for_delivery' && $this->mode === 'delivery')
                ? app(\App\Services\DeliveryOtpService::class)->plain($this)
                : null;
        }

        if ($staff) {
            $data['id'] = $this->id;
            $data['branch_id'] = $this->branch_id;
            $data['sales_bill_id'] = $this->sales_bill_id;
            $data['cancel_reason'] = $this->cancel_reason;
            $data['delivered_at'] = $this->delivered_at?->toIso8601String();
            // OTP ki value POS staff ko bhi nahi dikhti, sirf status
            $data['otp'] = [
                'issued' => ! empty($this->delivery_otp_enc),
                'sms_sent' => (bool) $this->delivery_otp_sent_at,
                'locked_until' => $this->delivery_otp_locked_until?->isFuture()
                    ? $this->delivery_otp_locked_until->toIso8601String() : null,
                'overridden' => (bool) $this->delivery_otp_overridden,
            ];
            $data['cod_collected'] = $this->cod_collected;
        }

        return $data;
    }

    /**
     * Delivery person ke liye sirf kaam ki cheezein (bill id, branch, history, OTP jaisi andar ki baatein nahi).
     */
    public function courierArray(): array
    {
        $this->loadMissing('items');

        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'total' => $this->total,
            'store' => [
                'name' => $this->store_name,
                'phone' => $this->store_phone,
            ],
            'customer_name' => $this->customer_name,
            'customer_mobile' => $this->customer_mobile,
            'address' => [
                'name' => $this->addr_name,
                'mobile' => $this->addr_mobile,
                'line1' => $this->addr_line1,
                'line2' => $this->addr_line2,
                'landmark' => $this->addr_landmark,
                'city' => $this->addr_city,
                'pincode' => $this->addr_pincode,
                // Customer ne map par jahan pin rakha tha wahi exact jagah
                'lat' => $this->addr_lat,
                'lng' => $this->addr_lng,
                'has_pin' => (bool) ($this->addr_lat && $this->addr_lng),
                'maps_url' => $this->mapsUrl(),
            ],
            'note' => $this->note,
            'items' => $this->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name' => $i->name,
                'price_unit' => $i->price_unit,
                'qty' => $i->qty,
            ])->values(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'otp_locked_until' => $this->delivery_otp_locked_until?->isFuture()
                ? $this->delivery_otp_locked_until->toIso8601String() : null,
            'otp_resends_left' => max(0, \App\Services\DeliveryOtpService::MAX_RESENDS - (int) $this->delivery_otp_resends),
            'cancel_reason' => $this->cancel_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cod_collected' => $this->cod_collected,
        ];
    }

    /** List ke liye chhota roop. */
    public function summaryArray(): array
    {
        $this->loadMissing('items');

        return [
            'order_no' => $this->order_no,
            'status' => $this->status,
            'mode' => $this->mode,
            'total' => $this->total,
            'store' => ['name' => $this->store_name, 'slug' => $this->store_slug],
            'placed_at' => $this->placed_at?->toIso8601String(),
            'items_count' => $this->items->count(),
            'items_preview' => $this->items->take(3)->pluck('name')->values(),
        ];
    }

    public function deliveryUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'delivery_user_id');
    }
}