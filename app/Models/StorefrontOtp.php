<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontOtp extends Model
{
    protected $fillable = ['mobile', 'otp_hash', 'expires_at', 'attempts', 'verified_at', 'ip'];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
}