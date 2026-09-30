<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFeature extends Model
{
    protected $fillable = ['user_id', 'feature_key'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
