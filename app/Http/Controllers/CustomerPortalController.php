<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPortalOtp;
use App\Models\CustomerPortalSession;
use App\Models\CustomerWalletTransaction;
use App\Models\SalesBill;
use Illuminate\Http\Request;
use Illuminate\Support\Str;


class CustomerPortalController extends Controller
{
    public function sendOtp(Request $request)
    {
        $request->validate([
            'mobile' => ['required', 'digits:10'],
        ]);

        $customer = Customer::where('mobile', $request->mobile)->first();

        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => 'Customer not found.',
            ], 404);
        }

        $otp = (string) random_int(100000, 999999);

        CustomerPortalOtp::where('mobile', $request->mobile)
            ->whereNull('verified_at')
            ->delete();

        CustomerPortalOtp::create([
            'mobile' => $request->mobile,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(5),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'OTP generated successfully.',
            'otp' => $otp,
        ]);
    }
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'mobile' => ['required', 'digits:10'],
            'otp' => ['required', 'digits:6'],
        ]);

        $otpRecord = CustomerPortalOtp::where('mobile', $request->mobile)
            ->where('otp', $request->otp)
            ->whereNull('verified_at')
            ->latest()
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP.',
            ], 422);
        }

        if ($otpRecord->expires_at->isPast()) {
            return response()->json([
                'status' => false,
                'message' => 'OTP has expired.',
            ], 422);
        }

        $customer = Customer::where('mobile', $request->mobile)->first();

        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => 'Customer not found.',
            ], 404);
        }

        $otpRecord->update([
            'verified_at' => now(),
        ]);

        $token = Str::random(64);

        CustomerPortalSession::create([
            'customer_id' => $customer->id,
            'token' => $token,
            'expires_at' => now()->addHours(24),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'OTP verified successfully.',
            'token' => $token,
            'customer' => $customer,
        ]);
    }
    public function dashboard(Request $request)
{
    $token = $request->bearerToken();

    if (!$token) {
        return response()->json([
            'status' => false,
            'message' => 'Unauthorized.'
        ], 401);
    }

    $session = CustomerPortalSession::where('token', $token)
        ->where('expires_at', '>', now())
        ->first();

    if (!$session) {
        return response()->json([
            'status' => false,
            'message' => 'Session expired or invalid.'
        ], 401);
    }

    $customer = Customer::find($session->customer_id);

    if (!$customer) {
        return response()->json([
            'status' => false,
            'message' => 'Customer not found.'
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
                'lines.product'
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
        ],

        'summary' => [
            'total_advance' => (float) $totalAdvance,
            'total_used' => (float) $totalUsed,
            'remaining' => $remaining,
        ],

        'transactions' => $transactions->values(),
    ]);
}
}
