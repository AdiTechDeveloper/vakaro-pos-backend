<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPortalOtp;
use App\Models\CustomerPortalSession;
use App\Models\CustomerWalletTransaction;
use App\Models\SalesBill;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerPortalController extends Controller
{
    public function sendOtp(Request $request)
    {
        $validated = $request->validate([
            'mobile' => ['required', 'digits:10'],
        ]);

        $mobile = $validated['mobile'];

        if (! Customer::where('mobile', $mobile)->exists()) {
            return response()->json(['status' => false, 'message' => 'Customer not found.'], 404);
        }

        $apiKey = config('services.textbee.api_key');
        $baseUrl = rtrim(config('services.textbee.base_url', ''), '/');
        $deviceId = config('services.textbee.device_id');

        if (! $apiKey || ! $baseUrl) {
            Log::error('TextBee configuration is incomplete.');

            return response()->json([
                'status' => false,
                'message' => 'SMS service is not configured.',
            ], 500);
        }

        $otp = (string) random_int(100000, 999999);

        try {
            $response = Http::timeout(15)
                ->withHeaders(['x-api-key' => $apiKey])
                ->post($baseUrl.'/gateway/send-sms', [
                    'recipients' => ['+91'.$mobile],
                    'message' => "Your Customer Portal OTP is {$otp}. It is valid for 5 minutes. Do not share it.",
                    ...($deviceId ? ['deviceId' => $deviceId] : []),
                ]);
        } catch (ConnectionException $exception) {
            Log::error('TextBee connection failed.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'SMS service is temporarily unavailable.',
            ], 502);
        }

        $body = $response->json();
        $data = data_get($body, 'data', []);

        // TextBee may return queued success or immediate dispatch counts.
        $smsAccepted = $response->successful() && (
            data_get($body, 'data.success') === true ||
            (
                (int) data_get($data, 'successCount', 0) > 0 &&
                (int) data_get($data, 'failureCount', 0) === 0
            )
        );

        if (! $smsAccepted) {
            Log::error('TextBee OTP SMS failed.', [
                'status' => $response->status(),
                'response_body' => $body,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'OTP SMS could not be sent. Please try again.',
            ], 502);
        }

        CustomerPortalOtp::where('mobile', $mobile)
            ->whereNull('verified_at')
            ->delete();

        CustomerPortalOtp::create([
            'mobile' => $mobile,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'verified_at' => null,
            'attempts' => 0,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'OTP sent successfully.',
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $validated = $request->validate([
            'mobile' => ['required', 'digits:10'],
            'otp' => ['required', 'digits:6'],
            // Multiple stores case: user picks which customer account to open
            'customer_id' => ['nullable', 'integer'],
        ]);

        $otpRecord = CustomerPortalOtp::where('mobile', $validated['mobile'])
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $otpRecord || $otpRecord->expires_at->isPast()) {
            return response()->json([
                'status' => false,
                'message' => 'OTP is invalid or expired. Request a new OTP.',
            ], 422);
        }

        if ($otpRecord->attempts >= 5) {
            return response()->json([
                'status' => false,
                'message' => 'Too many attempts. Request a new OTP.',
            ], 429);
        }

        if (! Hash::check($validated['otp'], $otpRecord->otp_hash)) {
            $otpRecord->increment('attempts');

            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP.',
            ], 422);
        }

        $customers = Customer::with('store')
            ->where('mobile', $validated['mobile'])
            ->get();

        if ($customers->isEmpty()) {
            return response()->json(['status' => false, 'message' => 'Customer not found.'], 404);
        }

        // Sirf isi mobile ke customers mein se dhundhta hai, isliye
        // kisi aur ka customer_id bhejne se koi fayda nahi.
        $customer = $customers->count() === 1
            ? $customers->first()
            : $customers->firstWhere('id', (int) ($validated['customer_id'] ?? 0));

        if (! $customer) {
            // OTP abhi verified mark nahi hota, taaki store select ke baad
            // wahi OTP dobara use ho sake (5 min expiry ke andar).
            return response()->json([
                'status' => true,
                'requires_selection' => true,
                'customers' => $customers->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'store' => $c->store?->name,
                ])->values(),
            ]);
        }

        $token = Str::random(64);

        $otpRecord->update([
            'verified_at' => now(),
        ]);

        CustomerPortalSession::create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(24),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'OTP verified successfully.',
            'token' => $token,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'mobile' => $customer->mobile,
                'store' => $customer->store?->name,
            ],
        ]);
    }

    public function dashboard(Request $request)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $session = CustomerPortalSession::where(
            'token_hash',
            hash('sha256', $token)
        )
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            return response()->json([
                'status' => false,
                'message' => 'Session expired or invalid.',
            ], 401);
        }

        $customer = Customer::with('store')->find($session->customer_id);

        if (! $customer) {
            return response()->json([
                'status' => false,
                'message' => 'Customer not found.',
            ], 404);
        }

        $transactions = CustomerWalletTransaction::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $totalAdvance = $transactions
            ->where('type', 'credit')
            ->sum('amount');

        $totalUsed = $transactions
            ->where('type', 'debit')
            ->sum('amount');

        // NOTE: agar opening_balance sirf shuruaati balance hai (current wallet
        // balance nahi), to yahan latest transaction ka balance_after ya
        // opening_balance + advance - used use karna hoga.
        $remaining = (float) $customer->opening_balance;

        $transactions = $transactions->map(function ($transaction) {

            $data = [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => (float) $transaction->amount,
                'balance_before' => (float) $transaction->balance_before,
                'balance_after' => (float) $transaction->balance_after,
                'source_type' => $transaction->source_type,
                'source_id' => $transaction->source_id,
                'note' => $transaction->note,
                'created_at' => $transaction->created_at,
                'bill' => null,
            ];

            if (
                $transaction->type === 'debit' &&
                $transaction->source_type === 'bill_applied' &&
                $transaction->source_id
            ) {
                $bill = SalesBill::with([
                    'lines.product',
                ])->find($transaction->source_id);

                if ($bill) {
                    $data['bill'] = [
                        'id' => $bill->id,
                        'bill_no' => $bill->bill_no,
                        'bill_date' => $bill->created_at,
                        'total_amount' => (float) $bill->total_amount,
                        'paid_amount' => (float) $bill->paid_amount,
                        'due_amount' => (float) $bill->due_amount,
                        'payment_status' => $bill->payment_status,

                        'items' => $bill->lines->map(function ($line) {
                            return [
                                'id' => $line->id,
                                'product_id' => $line->product_id,
                                'product_name' => $line->product?->name,
                                'qty' => (float) $line->qty,
                                'rate' => (float) $line->rate,
                                'amount' => (float) $line->amount,
                            ];
                        })->values(),
                    ];
                }
            }

            return $data;
        });

        return response()->json([
            'status' => true,

            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'mobile' => $customer->mobile,
                'store' => $customer->store?->name,
            ],

            // Same mobile ke saare store accounts (store dropdown ke liye)
            'stores' => Customer::with('store')
                ->where('mobile', $customer->mobile)
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'store' => $c->store?->name,
                ])
                ->values(),

            'summary' => [
                'total_advance' => (float) $totalAdvance,
                'total_used' => (float) $totalUsed,
                'remaining' => $remaining,
            ],

            'transactions' => $transactions->values(),
        ]);
    }

    /**
     * Same mobile ke dusre store account par switch karo (logout/OTP ke bina).
     * Session OTP se is mobile ke liye already verified hai, isliye sirf
     * usi mobile ke customers par switch allowed hai.
     */
    public function switchStore(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer'],
        ]);

        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $session = CustomerPortalSession::where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            return response()->json([
                'status' => false,
                'message' => 'Session expired or invalid.',
            ], 401);
        }

        $current = Customer::find($session->customer_id);

        if (! $current) {
            return response()->json([
                'status' => false,
                'message' => 'Customer not found.',
            ], 404);
        }

        $target = Customer::with('store')
            ->where('mobile', $current->mobile)
            ->where('id', $validated['customer_id'])
            ->first();

        if (! $target) {
            return response()->json([
                'status' => false,
                'message' => 'This store is not available for your account.',
            ], 403);
        }

        $session->update(['customer_id' => $target->id]);

        return response()->json([
            'status' => true,
            'message' => 'Store switched successfully.',
            'customer' => [
                'id' => $target->id,
                'name' => $target->name,
                'mobile' => $target->mobile,
                'store' => $target->store?->name,
            ],
        ]);
    }
}