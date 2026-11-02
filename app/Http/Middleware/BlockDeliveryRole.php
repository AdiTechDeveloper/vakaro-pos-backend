<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * "delivery" role wala user sirf apne delivery APIs use kar sakta hai.
 *
 * Ye middleware poore 'api' group par lagta hai (bootstrap/app.php), isliye naya POS route jodne par bhi
 * delivery ka token wahan nahi chalega. Allowlist ke bahar har authenticated request 403.
 */
class BlockDeliveryRole
{
    /** Delivery user ke liye khule raste */
    private const ALLOWED = [
        'api/delivery',
        'api/delivery/*',
        'api/logout',
        'api/user/change-password',
    ];

    public function handle(Request $request, Closure $next)
    {
        // Is middleware ke waqt route-level auth abhi nahi chala, isliye sanctum guard se seedha user
        $user = $request->user('sanctum');

        if ($user && $user->role === 'delivery' && ! $request->is(...self::ALLOWED)) {
            return response()->json(['status' => false, 'message' => 'Not allowed.'], 403);
        }

        return $next($request);
    }
}