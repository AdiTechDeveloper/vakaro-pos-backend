<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineOrderItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty' => 'float',
        'price' => 'float',
        'mrp' => 'float',
        'amount' => 'float',
    ];
}