<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineOrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];
}