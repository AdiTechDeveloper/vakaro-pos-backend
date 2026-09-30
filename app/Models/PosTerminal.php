<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosTerminal extends Model
{
    protected $fillable = [
        'store_id', 'branch_id', 'device_token', 'device_label',
        'registered_by', 'last_used_at', 'is_active',
    ];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
