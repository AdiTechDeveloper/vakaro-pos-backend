<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineStockAllocation extends Model
{
    protected $fillable = ['product_id', 'branch_id', 'allocated_qty', 'allocated_at'];

    protected $casts = [
        'allocated_qty' => 'decimal:2',
        'allocated_at' => 'datetime',
    ];
}
