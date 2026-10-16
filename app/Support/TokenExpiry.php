<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class TokenExpiry
{
    public static function next(): Carbon
    {
        $now = Carbon::now();

        return $now->copy()->addHours(8)->min($now->copy()->endOfDay());
    }
}
