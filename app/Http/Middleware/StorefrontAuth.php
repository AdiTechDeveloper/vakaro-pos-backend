<?php

namespace App\Http\Middleware;

use App\Models\StorefrontSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Storefront customer ka bearer token check karta hai.
 * Ye Sanctum se bilkul alag hai, isliye is token se POS/staff ke routes kabhi nahi khulte.
 * Route mein seedha class likho: ->middleware(StorefrontAuth::class)
 */
class StorefrontAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorized();
        }

        $session = StorefrontSession::with('customer')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (! $session || ! $session->customer) {
            return $this->unauthorized();
        }

        // har request pe DB write na ho, isliye 10 minute mein ek baar
        if (! $session->last_used_at || $session->last_used_at->lt(now()->subMinutes(10))) {
            $session->forceFill(['last_used_at' => now()])->save();
        }

        $request->attributes->set('storefront_session', $session);
        $request->attributes->set('storefront_customer', $session->customer);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'status' => false,
            'message' => 'Please log in again.',
        ], 401);
    }
}