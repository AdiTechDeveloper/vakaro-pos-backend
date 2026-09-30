<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosTerminal;
use App\Models\User;
use App\Services\FeatureService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required_without:pin|string',
            'password' => 'required_without:pin|string',
            'branch_id' => 'nullable|exists:branches,id',
            'device_token' => 'nullable|string',
            'pin' => 'required_without_all:username,password|digits:4',
        ]);

        if ($request->has('pin')) {
            $branchId = $request->branch_id;

            if ($request->filled('device_token')) {
                $terminal = PosTerminal::where('device_token', $request->device_token)
                    ->where('is_active', true)
                    ->first();

                if (! $terminal) {
                    return response()->json(['message' => 'This device is not registered. Please set it up again.'], 401);
                }

                $branchId = $terminal->branch_id;
                $terminal->update(['last_used_at' => now()]);
            }

            if (! $branchId) {
                return response()->json(['message' => 'Branch is required.'], 422);
            }

            $rateLimitKey = 'pin-login:'.$request->branch_id.'|'.$request->ip();

            if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
                $seconds = RateLimiter::availableIn($rateLimitKey);

                return response()->json([
                    'message' => "Too many attempts. Try again in {$seconds} seconds.",
                ], 429);
            }

            $cashiers = User::where('role', 'cashier')
                ->where('is_active', true)
                ->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))
                ->get();

            $user = $cashiers->first(fn ($c) => Hash::check($request->pin, $c->pin_hash));

            if (! $user) {
                RateLimiter::hit($rateLimitKey, 60);

                return response()->json(['message' => 'Invalid cashier PIN'], 401);
            }

            RateLimiter::clear($rateLimitKey);
        } else {
            $user = User::where('username', $request->username)
                ->where('is_active', true)
                ->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }
        }

        $expiry = Carbon::now()->addHours(8);

        $abilities = $user->must_change_credentials ? ['password:change'] : ['*'];

        $plainTextToken = $user->createToken('auth_token', $abilities)->plainTextToken;
        $user->tokens()->latest()->first()->update(['expires_at' => $expiry]);

        return response()->json([
            'message' => 'Login successful',
            'must_change_credentials' => $user->must_change_credentials,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role,
                'store_id' => $user->store_id,
                'branch_ids' => $user->branches()->pluck('branches.id'),
                'is_active' => $user->is_active,
                'features' => FeatureService::effectiveFeatures($user),
            ],
            'token' => $plainTextToken,
            'expires_at' => $expiry->toDateTimeString(),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function changePassword(Request $request)
    {
        $currentUser = Auth::user();

        if ($request->has('user_id') && $request->user_id != $currentUser->id) {

            $request->validate([
                'user_id' => 'required|exists:users,id',
            ]);

            $targetUser = User::find($request->user_id);

            if (! $targetUser) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if ($currentUser->role === 'cashier') {
                return response()->json([
                    'message' => 'Unauthorized. Cashiers cannot modify other staff credentials.',
                ], 403);
            }

            if ($currentUser->role === 'admin') {
                if ($targetUser->store_id !== $currentUser->store_id || $targetUser->role === 'superadmin') {
                    return response()->json([
                        'message' => 'Unauthorized. This user does not belong to your store.',
                    ], 403);
                }
            }

            if ($currentUser->role === 'manager') {
                if ($targetUser->role !== 'cashier') {
                    return response()->json([
                        'message' => 'Unauthorized. Managers can only modify cashier passwords.',
                    ], 403);
                }

                $managerBranches = $currentUser->branches()->pluck('branches.id')->toArray();
                $cashierHasSameBranch = $targetUser->branches()->whereIn('branches.id', $managerBranches)->exists();

                if (! $cashierHasSameBranch) {
                    return response()->json([
                        'message' => 'Unauthorized. This cashier does not belong to your branch.',
                    ], 403);
                }
            }

            if ($targetUser->role === 'cashier') {
                $passwordRules = ['required', 'confirmed', 'regex:/^\d{4}$/'];
            } else {
                $passwordRules = ['required', 'confirmed', Password::min(6)];
            }

            $request->validate([
                'password' => $passwordRules,
            ], [
                'password.regex' => 'The cashier password must be exactly a 4-digit numeric PIN.',
            ]);

            $targetUser->update([
                'password' => Hash::make($request->password),
                'must_change_credentials' => false,
            ]);

            return response()->json([
                'message' => "Password for {$targetUser->name} ({$targetUser->role}) updated successfully.",
            ], 200);
        }

        if ($currentUser->role === 'cashier') {
            return response()->json([
                'message' => 'Unauthorized endpoint access for this role.',
            ], 403);
        }

        $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(6)],
        ]);

        $currentUser->update([
            'password' => Hash::make($request->password),
            'must_change_credentials' => false,
        ]);

        return response()->json([
            'message' => 'Your password has been changed successfully.',
        ], 200);
    }

    public function emergencyAdminReset(Request $request)
    {
        $rateLimitKey = 'emergency-reset:'.$request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'message' => "Too many attempts. Try again in {$seconds} seconds.",
            ], 429);
        }

        $request->validate([
            'admin_username' => 'required|string',
            'master_key' => 'required|string',
            'new_password' => ['required', 'confirmed', Password::min(6)],
        ]);

        $masterKey = config('app.master_recovery_key');

        if (! $masterKey || ! hash_equals($masterKey, (string) $request->master_key)) {
            RateLimiter::hit($rateLimitKey, 300);

            Log::warning('Emergency password reset — invalid key attempt', [
                'attempted_username' => $request->admin_username,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid recovery PIN.'], 403);
        }

        $user = User::where('username', $request->admin_username)->first();

        if (! $user || ! in_array($user->role, ['admin', 'manager'])) {
            RateLimiter::hit($rateLimitKey, 300);

            return response()->json(['message' => 'Reset not permitted for this username.'], 403);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
            'must_change_credentials' => false,
        ]);

        RateLimiter::clear($rateLimitKey);

        Log::warning('Emergency password reset used successfully', [
            'username' => $user->username,
            'role' => $user->role,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Password reset successful.'], 200);
    }
}
