<?php

namespace App\Services;

use App\Models\OnlineOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivery OTP: customer ke phone par jata hai, delivery wala jab OTP daalta hai tabhi order "delivered" hota hai.
 *
 * - OTP encrypted store hota hai (customer ke order page par fallback dikhane aur SMS dobara bhejne ke liye).
 *   Delivery person / POS staff ko OTP kabhi API se nahi milta.
 * - Galat OTP: MAX_ATTEMPTS ke baad LOCK_MINUTES ka lock.
 * - Resend: RESEND_COOLDOWN_SECONDS ke gap par, ek order par MAX_RESENDS baar.
 */
class DeliveryOtpService
{
    public const LENGTH = 4;

    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    public const MAX_RESENDS = 3;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Order ke liye OTP bana do (agar pehle se nahi hai) aur SMS bhejo. SMS gaya to true. */
    public function issue(OnlineOrder $order): bool
    {
        if (empty($order->delivery_otp_enc)) {
            $otp = str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
            $order->forceFill([
                'delivery_otp_enc' => Crypt::encryptString($otp),
                'delivery_otp_attempts' => 0,
                'delivery_otp_resends' => 0,
                'delivery_otp_locked_until' => null,
                'delivery_otp_sent_at' => null,
            ])->save();
        }

        return $this->send($order);
    }

    /** Naye delivery person ko assign hone par attempts / lock saaf (OTP wahi rehta hai jo customer ke paas hai). */
    public function resetAttempts(OnlineOrder $order): void
    {
        $order->forceFill(['delivery_otp_attempts' => 0, 'delivery_otp_locked_until' => null])->save();
    }

    public function plain(OnlineOrder $order): ?string
    {
        if (empty($order->delivery_otp_enc)) {
            return null;
        }
        try {
            return Crypt::decryptString($order->delivery_otp_enc);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resend (delivery person ya POS staff, dono ke liye).
     *
     * @return array{ok:bool,message:string,retry_after:int,code:int}
     */
    public function resend(OnlineOrder $order): array
    {
        return DB::transaction(function () use ($order) {
            $o = OnlineOrder::lockForUpdate()->find($order->id);

            if ($o->status !== 'out_for_delivery' || $o->mode !== 'delivery') {
                return ['ok' => false, 'message' => "Order is {$o->status}.", 'retry_after' => 0, 'code' => 409];
            }
            if ((int) $o->delivery_otp_resends >= self::MAX_RESENDS) {
                return ['ok' => false, 'message' => 'OTP resend limit reached. The customer can see the OTP on their order page.', 'retry_after' => 0, 'code' => 429];
            }
            if ($o->delivery_otp_sent_at) {
                $wait = self::RESEND_COOLDOWN_SECONDS - (int) $o->delivery_otp_sent_at->diffInSeconds(now(), true);
                if ($wait > 0) {
                    return ['ok' => false, 'message' => "Please wait {$wait} seconds before resending.", 'retry_after' => $wait, 'code' => 429];
                }
            }
            if (empty($o->delivery_otp_enc)) {
                // Purana order jisme OTP bana hi nahi tha
                $this->issue($o);
                $o->refresh();
            }

            $o->delivery_otp_resends = (int) $o->delivery_otp_resends + 1;
            $o->save();

            return $this->send($o)
                ? ['ok' => true, 'message' => 'OTP sent again to the customer.', 'retry_after' => self::RESEND_COOLDOWN_SECONDS, 'code' => 200]
                : ['ok' => false, 'message' => 'SMS could not be sent. The customer can see the OTP on their order page.', 'retry_after' => 0, 'code' => 502];
        });
    }

    /**
     * OTP check. Galat par attempts badhte hain (row lock ke saath, taaki ek saath kai try na chal sakein).
     *
     * @return array{ok:bool,message:string,code:int,attempts_left?:int,locked_until?:string}
     */
    public function verify(OnlineOrder $order, string $code): array
    {
        return DB::transaction(function () use ($order, $code) {
            $o = OnlineOrder::lockForUpdate()->find($order->id);

            if ($o->delivery_otp_locked_until && $o->delivery_otp_locked_until->isFuture()) {
                $mins = (int) ceil(now()->diffInSeconds($o->delivery_otp_locked_until, true) / 60);

                return [
                    'ok' => false,
                    'code' => 423,
                    'message' => "Too many wrong OTP tries. Locked for {$mins} more minute(s). Ask the store.",
                    'locked_until' => $o->delivery_otp_locked_until->toIso8601String(),
                ];
            }

            $plain = $this->plain($o);
            if ($plain === null) {
                return ['ok' => false, 'code' => 409, 'message' => 'No OTP found for this order. Ask the store to resend it.'];
            }

            if (hash_equals($plain, $code)) {
                $o->forceFill(['delivery_otp_attempts' => 0, 'delivery_otp_locked_until' => null])->save();

                return ['ok' => true, 'code' => 200, 'message' => 'OTP verified.'];
            }

            $attempts = (int) $o->delivery_otp_attempts + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $until = now()->addMinutes(self::LOCK_MINUTES);
                $o->forceFill(['delivery_otp_attempts' => 0, 'delivery_otp_locked_until' => $until])->save();

                return [
                    'ok' => false,
                    'code' => 423,
                    'message' => 'Too many wrong OTP tries. Locked for '.self::LOCK_MINUTES.' minutes. Ask the store.',
                    'locked_until' => $until->toIso8601String(),
                ];
            }

            $o->forceFill(['delivery_otp_attempts' => $attempts])->save();
            $left = self::MAX_ATTEMPTS - $attempts;

            return [
                'ok' => false,
                'code' => 422,
                'message' => "Wrong OTP. {$left} ".($left === 1 ? 'try' : 'tries').' left.',
                'attempts_left' => $left,
            ];
        });
    }

    /** Delivered / cancelled ke baad OTP hata do. */
    public function clear(OnlineOrder $order): void
    {
        $order->forceFill([
            'delivery_otp_enc' => null,
            'delivery_otp_attempts' => 0,
            'delivery_otp_locked_until' => null,
        ])->save();
    }

    /** Indian mobile ko E.164 banao: +91XXXXXXXXXX (sirf digits, aakhri 10). Galat ho to null. */
    private function e164(string $mobile): ?string
    {
        $digits = preg_replace('/\D+/', '', $mobile);
        if (strlen($digits) < 10) {
            return null;
        }
        $last10 = substr($digits, -10);

        return preg_match('/^[6-9]\d{9}$/', $last10) ? '+91'.$last10 : null;
    }

    /** TextBee (storefront login OTP wali hi config). Success par true. */
    private function send(OnlineOrder $order): bool
    {
        $otp = $this->plain($order);
        if ($otp === null) {
            return false;
        }

        $to = $this->e164((string) $order->customer_mobile);
        if ($to === null) {
            Log::error('Delivery OTP: invalid customer mobile.', ['order' => $order->order_no, 'mobile' => $order->customer_mobile]);

            return false;
        }
        $amount = $order->payment_method === 'cod'
            ? ' Pay Rs '.rtrim(rtrim(number_format((float) $order->total, 2, '.', ''), '0'), '.').' cash.'
            : '';
        // 160 characters ke andar rakho: ek hi SMS jaee (lamba SMS 2 parts mein jata hai aur phone par fail hone ka chance badhta hai)
        $message = "Vakaro: Order {$order->order_no} out for delivery. Delivery OTP {$otp}. Share only after you get your order.{$amount}";

        if (app()->environment('local')) {
            Log::info("Delivery OTP for {$order->order_no} ({$to}): {$otp}");
        }

        $apiKey = config('services.textbee.api_key');
        $baseUrl = rtrim((string) config('services.textbee.base_url', ''), '/');
        $deviceId = config('services.textbee.device_id');

        if (! $apiKey || ! $baseUrl) {
            Log::error('TextBee configuration is incomplete (delivery OTP).');

            return false;
        }

        // TextBee docs: POST /gateway/devices/{DEVICE_ID}/send-sms, recipients E.164 (+91XXXXXXXXXX)
        $url = $deviceId
            ? $baseUrl.'/gateway/devices/'.$deviceId.'/send-sms'
            : $baseUrl.'/gateway/send-sms';

        try {
            $response = Http::timeout(10)
                ->withHeaders(['x-api-key' => $apiKey])
                ->post($url, [
                    'recipients' => [$to],
                    'message' => $message,
                ]);
        } catch (ConnectionException $e) {
            Log::error('TextBee connection failed (delivery OTP).', ['error' => $e->getMessage()]);

            return false;
        }

        $body = $response->json();
        $d = data_get($body, 'data', []);
        $accepted = $response->successful() && (
            data_get($body, 'data.success') === true ||
            ((int) data_get($d, 'successCount', 0) > 0 && (int) data_get($d, 'failureCount', 0) === 0)
        );

        if (! $accepted) {
            Log::error('TextBee delivery OTP SMS failed.', ['status' => $response->status(), 'response_body' => $body]);

            return false;
        }

        $order->forceFill(['delivery_otp_sent_at' => now()])->save();

        return true;
    }
}