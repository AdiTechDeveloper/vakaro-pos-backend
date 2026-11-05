<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\StorefrontAddress;
use App\Models\StorefrontCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Logged-in storefront customer ke delivery addresses.
 * Har query customer ki apni id se bandhi hai, isliye koi doosre ka address nahi dekh/badal sakta.
 * (Order place hone par address ki copy order mein save hogi, isliye yahan hard delete theek hai.)
 */
class StorefrontAddressController extends Controller
{
    private const MAX_ADDRESSES = 10;

    // GET /api/public/addresses
    public function index(Request $request): JsonResponse
    {
        $rows = StorefrontAddress::where('storefront_customer_id', $this->customer($request)->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return response()->json(['status' => true, 'data' => $rows->map(fn ($a) => $this->format($a))->values()]);
    }

    // POST /api/public/addresses
    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $this->validated($request);

        if (StorefrontAddress::where('storefront_customer_id', $customer->id)->count() >= self::MAX_ADDRESSES) {
            return response()->json([
                'status' => false,
                'message' => 'You can save up to '.self::MAX_ADDRESSES.' addresses. Delete one to add a new address.',
            ], 422);
        }

        $address = DB::transaction(function () use ($customer, $data) {
            $isFirst = ! StorefrontAddress::where('storefront_customer_id', $customer->id)->exists();
            $makeDefault = $isFirst || ! empty($data['is_default']);

            if ($makeDefault) {
                StorefrontAddress::where('storefront_customer_id', $customer->id)->update(['is_default' => false]);
            }

            return StorefrontAddress::create(array_merge($data, [
                'storefront_customer_id' => $customer->id,
                'is_default' => $makeDefault,
            ]));
        });

        return response()->json(['status' => true, 'data' => $this->format($address)], 201);
    }

    // PUT /api/public/addresses/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        $address = StorefrontAddress::where('storefront_customer_id', $customer->id)->findOrFail($id);
        $data = $this->validated($request);

        DB::transaction(function () use ($customer, $address, $data) {
            if (! empty($data['is_default'])) {
                StorefrontAddress::where('storefront_customer_id', $customer->id)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
                $address->is_default = true;
            }
            // default se hata kar koi address "no default" nahi chhodte: default wala default hi rehta hai
            unset($data['is_default']);

            $address->fill($data)->save();
        });

        return response()->json(['status' => true, 'data' => $this->format($address->fresh())]);
    }

    // DELETE /api/public/addresses/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        $address = StorefrontAddress::where('storefront_customer_id', $customer->id)->findOrFail($id);

        DB::transaction(function () use ($customer, $address) {
            $wasDefault = $address->is_default;
            $address->delete();

            // default hata diya to sabse naya bacha hua address default ban jaye
            if ($wasDefault) {
                StorefrontAddress::where('storefront_customer_id', $customer->id)
                    ->orderByDesc('id')
                    ->first()
                    ?->update(['is_default' => true]);
            }
        });

        return response()->json(['status' => true, 'message' => 'Address deleted.']);
    }

    // line1 = flat / building text. Ek asli society / building ka naam hona chahiye, "402" ya "abc" nahi.
    private function realBuilding(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            $s = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $value)));
            $junk = ['na', 'n/a', 'none', 'nil', 'no', 'test', 'testing', 'abc', 'abcd', 'xyz', 'asdf', 'qwerty',
                'home', 'house', 'flat', 'room', 'office', 'hotel', 'address', 'my home', 'my house', 'ghar'];

            if (mb_strlen($s) < 3 || in_array($s, $junk, true) || preg_match('/^(.)\1{2,}$/u', $s)) {
                return $fail('Please enter your real flat / building name.');
            }
            if (! preg_match('/\p{L}{3,}/u', $s)) {
                return $fail('Please add your society or building name along with the flat / house number.');
            }
            if ($this->looksLikeGibberish($s)) {
                return $fail('This does not look like a real building or society name. Please check it.');
            }
        };
    }

    // Keyboard mashing ("fgnlkgnfklgfg...") pakadne ke liye. Koi bhi tool ye pakka nahi kar sakta ki building hai,
    // par random typing in rules se pakdi jati hai.
    private function looksLikeGibberish(string $s): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $w) {
            if (ctype_digit($w)) {
                continue;
            }
            if (mb_strlen($w) > 22) {
                return true; // itna lamba koi asli shabd nahi hota
            }
            if (preg_match('/^(.{3,4})\1+$/u', $w)) {
                return true; // "asdasd", "abcabcabc"
            }
            if (preg_match('/^[a-z]+$/', $w)) {
                // Sirf English letters wale shabd (Hindi / Gujarati naam check nahi hote)
                if (strlen($w) >= 4 && ! preg_match('/[aeiouy]/', $w)) {
                    return true; // ek bhi vowel nahi: "ghjk"
                }
                if (preg_match('/[^aeiouy]{6,}/', $w)) {
                    return true; // 6+ consonant ek saath: "sdfghjkl"
                }
            }
        }

        return false;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'in:home,work,hotel,other'],
            'name' => ['required', 'string', 'max:60'],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'line1' => ['required', 'string', 'max:150', $this->realBuilding()],
            'line2' => ['required', 'string', 'max:150'],
            'landmark' => ['nullable', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:60'],
            'pincode' => ['required', 'digits:6'],
            'is_default' => ['sometimes', 'boolean'],
            // Map ka exact pin: zaroori. Delivery wala isi par navigate karta hai. Sirf India ke andar.
            'lat' => ['required', 'numeric', 'between:6,38'],
            'lng' => ['required', 'numeric', 'between:68,98'],
        ], [
            'lat.required' => 'Please set your exact location on the map.',
            'lng.required' => 'Please set your exact location on the map.',
            'lat.between' => 'Your map pin looks outside India. Please set it again.',
            'lng.between' => 'Your map pin looks outside India. Please set it again.',
        ]);
    }

    private function customer(Request $request): StorefrontCustomer
    {
        return $request->attributes->get('storefront_customer');
    }

    private function format(StorefrontAddress $a): array
    {
        return [
            'id' => $a->id,
            'label' => $a->label,
            'name' => $a->name,
            'mobile' => $a->mobile,
            'line1' => $a->line1,
            'line2' => $a->line2,
            'landmark' => $a->landmark,
            'city' => $a->city,
            'pincode' => $a->pincode,
            'is_default' => (bool) $a->is_default,
            'lat' => $a->lat,
            'lng' => $a->lng,
        ];
    }
}