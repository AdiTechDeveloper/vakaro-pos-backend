<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPortalSession extends Model
{
    protected $fillable = [
        'customer_id',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}