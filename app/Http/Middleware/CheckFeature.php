<?php

namespace App\Http\Middleware;

use App\Services\FeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->role === 'cashier') {
            return $next($request);
        }

        if (! FeatureService::userHasFeature($user, $feature)) {
            return response()->json(['message' => 'This feature is not enabled for your account.'], 403);
        }

        return $next($request);
    }
}
