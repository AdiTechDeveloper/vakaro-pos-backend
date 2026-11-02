<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrescriptionRegister extends Model
{
    protected $guarded = [];

    protected $casts = [
        'dispensed_at' => 'datetime',
        'expiry_date' => 'date',
        'prescription_date' => 'date',
    ];
}
