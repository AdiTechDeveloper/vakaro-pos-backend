<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontAddress extends Model
{
    protected $fillable = [
        'storefront_customer_id', 'label', 'name', 'mobile', 'line1', 'line2',
        'landmark', 'city', 'pincode', 'is_default', 'lat', 'lng',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'lat' => 'float',
        'lng' => 'float',
    ];
}