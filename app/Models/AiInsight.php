<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiInsight extends Model
{
    protected $fillable = [
        'store_id', 'branch_id', 'insight_date', 'period_type', 'summary_json', 'ai_message',
    ];
}
