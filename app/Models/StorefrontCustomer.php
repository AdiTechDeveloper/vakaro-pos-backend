<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontCustomer extends Model
{
    protected $fillable = ['mobile', 'name', 'last_login_at'];

    protected $casts = ['last_login_at' => 'datetime'];
}