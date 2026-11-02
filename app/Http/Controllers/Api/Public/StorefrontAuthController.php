<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontOtp;
use App\Models\StorefrontSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Storefront customer login: mobile number + OTP.
 * Naya mobile ho to customer apne aap ban jata hai (online store pe sab naye hote hain).
 * Purane CustomerPortalController se iska koi lena-dena nahi.
 */
class StorefrontAuthController extends Controller
{
    private const OTP_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_SECONDS = 60;
    private const MAX_PER_MOBILE_HOUR = 5;
    private const MAX_PER_IP_HOUR = 20;
    private const SESSION_DAYS = 30;

    // POST /api/public/auth/send-otp  { mobile }
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
        ]);
        $mobile = $data['mobile'];

        // purani rows saaf
        StorefrontOtp::where('created_at', '<', now()->subDay())->delete();

        // 60 second ke andar dobara nahi
        $last = StorefrontOtp::where('mobile', $mobile)->latest('id')->first();
        if ($last) {
            $wait = $last->created_at->timestamp + self::RESEND_SECONDS - now()->timestamp;
            if ($wait > 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP already sent. Please wait before requesting again.',
                    'retry_after' => $wait,
                ], 429);
            }
        }

        // SMS ka kharcha na ho: ghante mein limit (mobile aur IP dono par)
        $since = now()->subHour();
        $byMobile = StorefrontOtp::where('mobile', $mobile)->where('created_at', '>=', $since)->count();
        $byIp = StorefrontOtp::where('ip', $request->ip())->where('created_at', '>=', $since)->count();
        if ($byMobile >= self::MAX_PER_MOBILE_HOUR || $byIp >= self::MAX_PER_IP_HOUR) {
            return response()->json([
                'status' => false,
                'message' => 'Too many OTP requests. Please try again after some time.',
            ], 429);
        }

        $otp = (string) random_int(100000, 999999);

        $record = StorefrontOtp::create([
            'mobile' => $mobile,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(self::OTP_MINUTES),
            'attempts' => 0,
            'ip' => $request->ip(),
        ]);

        if ($error = $this->sendSms($mobile, $otp)) {
            $record->delete(); // SMS gaya hi nahi, to user turant dobara try kar sake
            return $error;
        }

        return response()->json([
            'status' => true,
            'message' => 'OTP sent successfully.',
            'retry_after' => self::RESEND_SECONDS,
        ]);
    }

    // POST /api/public/auth/verify-otp  { mobile, otp }
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'otp' => ['required', 'digits:6'],
        ]);

        // sirf sabse naya OTP chalta hai, purane apne aap bekaar
        $record = StorefrontOtp::where('mobile', $data['mobile'])
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $record || $record->expires_at->isPast()) {
            return response()->json([
                'status' => false,
                'message' => 'OTP is invalid or expired. Request a new OTP.',
            ], 422);
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            return response()->json([
                'status' => false,
                'message' => 'Too many wrong attempts. Request a new OTP.',
            ], 429);
        }

        if (! Hash::check($data['otp'], $record->otp_hash)) {
            $record->increment('attempts');
            $left = max(0, self::MAX_ATTEMPTS - $record->attempts);

            return response()->json([
                'status' => false,
                'message' => $left > 0 ? "Wrong OTP. {$left} attempts left." : 'Too many wrong attempts. Request a new OTP.',
            ], 422);
        }

        $token = Str::random(64);

        $customer = DB::transaction(function () use ($record, $data, $token, $request) {
            $record->update(['verified_at' => now()]);

            $customer = StorefrontCustomer::firstOrCreate(['mobile' => $data['mobile']]);
            $customer->update(['last_login_at' => now()]);

            StorefrontSession::where('storefront_customer_id', $customer->id)
                ->where('expires_at', '<', now())
                ->delete();

            StorefrontSession::create([
                'storefront_customer_id' => $customer->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(self::SESSION_DAYS),
                'last_used_at' => now(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ]);

            return $customer;
        });

        return response()->json([
            'status' => true,
            'message' => 'Logged in.',
            'token' => $token,
            'customer' => $this->customerData($customer),
        ]);
    }

    // GET /api/public/auth/me   (token chahiye)
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => true,
            'customer' => $this->customerData($request->attributes->get('storefront_customer')),
        ]);
    }

    // POST /api/public/auth/logout   (token chahiye)
    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('storefront_session')?->delete();

        return response()->json(['status' => true, 'message' => 'Logged out.']);
    }

    private function customerData(StorefrontCustomer $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'mobile' => $c->mobile];
    }

    /**
     * SMS bhejta hai (purane portal wali hi TextBee config use hoti hai).
     * Success par null, fail par ready JSON error response deta hai.
     */
    private function sendSms(string $mobile, string $otp): ?JsonResponse
    {
        // Local machine par SMS nahi jata, OTP storage/logs/laravel.log mein milta hai
                // Local par debugging ke liye OTP log mein bhi likhte hain (SMS phir bhi jata hai)
        if (app()->environment('local')) {
            Log::info("Storefront OTP for {$mobile}: {$otp}");
        }

        $apiKey = config('services.textbee.api_key');
        $baseUrl = rtrim((string) config('services.textbee.base_url', ''), '/');
        $deviceId = config('services.textbee.device_id');

        if (! $apiKey || ! $baseUrl) {
            Log::error('TextBee configuration is incomplete.');

            return response()->json(['status' => false, 'message' => 'SMS service is not configured.'], 500);
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['x-api-key' => $apiKey])
                ->post($baseUrl.'/gateway/send-sms', [
                    'recipients' => ['+91'.$mobile],
                    'message' => "Your Vakaro login OTP is {$otp}. Valid for ".self::OTP_MINUTES.' minutes. Do not share it.',
                    ...($deviceId ? ['deviceId' => $deviceId] : []),
                ]);
        } catch (ConnectionException $e) {
            Log::error('TextBee connection failed.', ['error' => $e->getMessage()]);

            return response()->json(['status' => false, 'message' => 'SMS service is temporarily unavailable.'], 502);
        }

        $body = $response->json();
        $d = data_get($body, 'data', []);

        $accepted = $response->successful() && (
            data_get($body, 'data.success') === true ||
            ((int) data_get($d, 'successCount', 0) > 0 && (int) data_get($d, 'failureCount', 0) === 0)
        );

        if (! $accepted) {
            Log::error('TextBee OTP SMS failed.', ['status' => $response->status(), 'response_body' => $body]);

            return response()->json(['status' => false, 'message' => 'OTP SMS could not be sent. Please try again.'], 502);
        }

        return null;
    }
}