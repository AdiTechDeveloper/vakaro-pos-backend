<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OnlineOrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OnlineOrderEvent::class)->orderBy('id');
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
            ] : null,
            'note' => $this->note,
            'subtotal' => $this->subtotal,
            'delivery_fee' => $this->delivery_fee,
            'total' => $this->total,
            'total_saved' => $this->total_saved,
            'placed_at' => $this->placed_at?->toIso8601String(),
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