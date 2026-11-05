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
    ];

    protected static function booted(): void
    {
        // Delivery wala order raste mein nikla: ab sirf raste ka time bacha hai.
        // Ye yahin model mein hai, isliye staff ka status badalne wala code chhedna nahi padta.
        static::updating(function (OnlineOrder $order) {
            if ($order->isDirty('status')
                && $order->status === 'out_for_delivery'
                && $order->mode === 'delivery') {
                $order->eta_ts = time() + ((int) $order->travel_minutes) * 60;
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