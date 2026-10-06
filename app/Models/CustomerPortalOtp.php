<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPortalOtp extends Model
{
    protected $fillable = [
        'mobile',
        'otp',
        'expires_at',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
}