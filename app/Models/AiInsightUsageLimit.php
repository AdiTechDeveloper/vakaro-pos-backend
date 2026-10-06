<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiInsightUsageLimit extends Model
{
    protected $fillable = [
        'store_id', 'branch_id', 'usage_date', 'generate_count',
    ];
}
