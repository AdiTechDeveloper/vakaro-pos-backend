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

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'in:home,work,other'],
            'name' => ['required', 'string', 'max:60'],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'line1' => ['required', 'string', 'max:150'],
            'line2' => ['required', 'string', 'max:150'],
            'landmark' => ['nullable', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:60'],
            'pincode' => ['required', 'digits:6'],
            'is_default' => ['sometimes', 'boolean'],
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
        ];
    }
}