<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreFeature extends Model
{
    protected $fillable = ['store_id', 'feature_key'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
