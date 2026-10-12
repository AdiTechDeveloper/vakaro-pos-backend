<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPortalOtp extends Model
{
    protected $fillable = [
        'mobile',
        'otp_hash',
        'expires_at',
        'verified_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}