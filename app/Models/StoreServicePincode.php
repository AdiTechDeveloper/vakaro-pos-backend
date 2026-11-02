<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreServicePincode extends Model
{
    protected $fillable = ['store_id', 'pincode', 'delivery_charge', 'min_order_amount', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'delivery_charge' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
    ];
}
