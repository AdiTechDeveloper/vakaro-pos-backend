<?php

namespace App\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Http\Request;

class EnsureStoreActive
{
    public function handle(Request $request, Closure $next)
    {
        // Resolve the token user even though auth:sanctum has not run yet on this route.
        $user = $request->user('sanctum');

        if ($user && $user->role !== 'superadmin' && $user->store_id) {
            $store = Store::find($user->store_id);

            if ($store && ! $store->isActive()) {
                return response()->json([
                    'status' => false,
                    'code' => 'STORE_SUSPENDED',
                    'message' => Store::SUSPENDED_MESSAGE,
                ], 403);
            }
        }

        return $next($request);
    }
}
