<?php

namespace App\Http\Middleware;

use App\Support\TokenExpiry;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ExtendTokenExpiry
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->expires_at) {
            $newExpiry = TokenExpiry::next();

            if ($newExpiry->diffInMinutes($token->expires_at, false) <= -10) {
                $token->forceFill(['expires_at' => $newExpiry])->save();
            }
        }

        return $next($request);
    }
}
