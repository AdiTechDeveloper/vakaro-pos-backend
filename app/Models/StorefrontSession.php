<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontSession extends Model
{
    protected $fillable = ['storefront_customer_id', 'token_hash', 'expires_at', 'last_used_at', 'user_agent'];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(StorefrontCustomer::class, 'storefront_customer_id');
    }
}