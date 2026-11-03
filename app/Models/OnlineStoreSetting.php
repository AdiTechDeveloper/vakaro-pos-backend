<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnlineStoreSetting extends Model
{
    protected $fillable = [
        'store_id', 'slug', 'display_name', 'logo', 'banner', 'about', 'phone', 'address',
        'lat', 'lng', 'is_online_enabled', 'open_time', 'close_time',
        'fulfil_branch_id', 'online_stock_percent',
        'accepts_delivery', 'accepts_pickup', 'min_order_amount', 'delivery_charge',
        'free_delivery_above', 'prep_minutes','max_qty_per_product'
    ];

    protected $casts = [
        'is_online_enabled' => 'boolean',
        'accepts_delivery' => 'boolean',
        'accepts_pickup' => 'boolean',
        'online_stock_percent' => 'integer',
        'min_order_amount' => 'decimal:2',
        'delivery_charge' => 'decimal:2',
        'free_delivery_above' => 'decimal:2',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function pincodes(): HasMany
    {
        return $this->hasMany(StoreServicePincode::class, 'store_id', 'store_id');
    }
}
