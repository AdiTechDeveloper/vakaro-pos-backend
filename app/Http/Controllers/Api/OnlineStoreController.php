<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesOnlineScope;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\OnlineStoreSetting;
use App\Models\Store;
use App\Models\StoreServicePincode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * POS ke Admin ke liye "Online Store Settings": timings, delivery/pickup, charges,
 * online stock %, aur delivery pincodes. Sirf store admin badal sakta hai.
 */
class OnlineStoreController extends Controller
{
    use ResolvesOnlineScope;

    // GET /api/online/settings
    public function show(Request $request): JsonResponse
    {
        [$storeId] = $this->onlineScope($request, true);

        return response()->json(['status' => true, 'data' => $this->payload($storeId)]);
    }

    // PUT /api/online/settings
    public function update(Request $request): JsonResponse
    {
        [$storeId] = $this->onlineScope($request, true);

        $existing = OnlineStoreSetting::where('store_id', $storeId)->first();

        $data = $request->validate([
            'is_online_enabled' => ['required', 'boolean'],
            'slug' => [
                'nullable', 'string', 'max:100',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                'not_regex:/-b\d+$/', // "-b<number>" branch ke liye reserved hai
                Rule::unique('online_store_settings', 'slug')->ignore($existing?->id),
            ],
            'display_name' => ['nullable', 'string', 'max:150'],
            'about' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'open_time' => ['nullable', 'required_with:close_time', 'date_format:H:i'],
            'close_time' => ['nullable', 'required_with:open_time', 'date_format:H:i'],
            'accepts_delivery' => ['required', 'boolean'],
            'accepts_pickup' => ['required', 'boolean'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'free_delivery_above' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'online_stock_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ], [
            'slug.regex' => 'Store link can have only small letters, numbers and hyphens (for example: my-store).',
            'slug.not_regex' => 'Store link cannot end with -b and a number (that is used for branches).',
            'slug.unique' => 'This store link is already taken. Please choose another.',
            'open_time.required_with' => 'Enter both opening and closing time, or leave both empty.',
            'close_time.required_with' => 'Enter both opening and closing time, or leave both empty.',
        ]);

        if ($data['is_online_enabled'] && ! $data['accepts_delivery'] && ! $data['accepts_pickup']) {
            return response()->json([
                'status' => false,
                'message' => 'Turn on at least one of delivery or pickup.',
                'errors' => ['accepts_delivery' => ['Turn on at least one of delivery or pickup.']],
            ], 422);
        }

        $store = Store::findOrFail($storeId);

        $slug = $data['slug'] ?? null;
        if (! $slug) {
            $slug = $existing?->slug ?: (Str::slug((string) $store->name) ?: 'store');
            $taken = OnlineStoreSetting::where('slug', $slug)->where('store_id', '!=', $storeId)->exists();
            if ($taken || preg_match('/-b\d+$/', $slug)) {
                $slug = Str::slug((string) $store->name).'-'.$storeId;
            }
        }

        $blank = fn ($v) => ($v === null || $v === '') ? null : $v;

        OnlineStoreSetting::updateOrCreate(
            ['store_id' => $storeId],
            [
                'is_online_enabled' => (bool) $data['is_online_enabled'],
                'slug' => $slug,
                'display_name' => $blank($data['display_name'] ?? null),
                'about' => $blank($data['about'] ?? null),
                'phone' => $blank($data['phone'] ?? null),
                'address' => $blank($data['address'] ?? null),
                'open_time' => $blank($data['open_time'] ?? null),
                'close_time' => $blank($data['close_time'] ?? null),
                'accepts_delivery' => (bool) $data['accepts_delivery'],
                'accepts_pickup' => (bool) $data['accepts_pickup'],
                'min_order_amount' => $data['min_order_amount'] ?? 0,
                'delivery_charge' => $data['delivery_charge'] ?? 0,
                'free_delivery_above' => $blank($data['free_delivery_above'] ?? null),
                'prep_minutes' => $data['prep_minutes'] ?? 30,
                'online_stock_percent' => (int) $data['online_stock_percent'],
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'Online store settings saved.',
            'data' => $this->payload($storeId),
        ]);
    }

    // GET /api/online/pincodes
    public function pincodes(Request $request): JsonResponse
    {
        [$storeId] = $this->onlineScope($request, true);

        return response()->json(['status' => true, 'data' => $this->pincodeList($storeId)]);
    }

    // POST /api/online/pincodes   { pincodes: "380001, 380015\n380054", delivery_charge?, min_order_amount? }
    public function addPincodes(Request $request): JsonResponse
    {
        [$storeId] = $this->onlineScope($request, true);

        $data = $request->validate([
            'pincodes' => ['required'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $raw = is_array($data['pincodes']) ? implode(' ', $data['pincodes']) : (string) $data['pincodes'];
        $list = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', $raw))));

        if (! $list) {
            return $this->fail('Enter at least one pincode.', 422);
        }
        if (count($list) > 200) {
            return $this->fail('You can add up to 200 pincodes at a time.', 422);
        }

        $invalid = array_values(array_filter($list, fn ($p) => ! preg_match('/^\d{6}$/', $p)));
        if ($invalid) {
            return $this->fail('These are not valid 6-digit pincodes: '.implode(', ', array_slice($invalid, 0, 5)), 422);
        }

        $added = 0;
        foreach ($list as $pin) {
            $row = StoreServicePincode::firstOrNew(['store_id' => $storeId, 'pincode' => $pin]);
            if (! $row->exists) {
                $added++;
            }
            $row->is_active = true;
            if (array_key_exists('delivery_charge', $data) && $data['delivery_charge'] !== null) {
                $row->delivery_charge = $data['delivery_charge'];
            }
            if (array_key_exists('min_order_amount', $data) && $data['min_order_amount'] !== null) {
                $row->min_order_amount = $data['min_order_amount'];
            }
            $row->save();
        }

        return response()->json([
            'status' => true,
            'message' => $added.' new pincode'.($added === 1 ? '' : 's').' added.',
            'data' => $this->pincodeList($storeId),
        ]);
    }

    // PUT /api/online/pincodes/{id}   { delivery_charge?, min_order_amount?, is_active? }  (null = store ki default)
    public function updatePincode(Request $request, int $id): JsonResponse
    {
        [$storeId] = $this->onlineScope($request, true);

        $row = StoreServicePincode::where('store_id', $storeId)->find($id);
        abort_if(! $row, 404, 'Pincode not found.');

        $data = $request->validate([
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        foreach (['delivery_charge', 'min_order_amount'] as $field) {
            if ($request->exists($field)) {
                $row->{$field} = $data[$field] ?? null;
            }
        }
        if ($request->exists('is_active')) {
            $row->is_active = (bool) $data['is_active'];
        }
        $row->save();

        return response()->json(['status' => true, 'data' => $this->pincodeList($storeId)]);
    }

    // DELETE /api/online/pincodes/{id}
    public function destroyPincode(Request $request, int $id): JsonResponse
    {
        [$storeId] = $this->onlineScope($request, true);

        $row = StoreServicePincode::where('store_id', $storeId)->find($id);
        abort_if(! $row, 404, 'Pincode not found.');
        $row->delete();

        return response()->json(['status' => true, 'data' => $this->pincodeList($storeId)]);
    }

    // ---------------------------------------------------------------- helpers

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => false, 'message' => $message], $status);
    }

    private function pincodeList(int $storeId): array
    {
        return StoreServicePincode::where('store_id', $storeId)
            ->orderBy('pincode')
            ->get(['id', 'pincode', 'delivery_charge', 'min_order_amount', 'is_active'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'pincode' => $p->pincode,
                'delivery_charge' => $p->delivery_charge !== null ? (float) $p->delivery_charge : null,
                'min_order_amount' => $p->min_order_amount !== null ? (float) $p->min_order_amount : null,
                'is_active' => (bool) $p->is_active,
            ])
            ->values()
            ->all();
    }

    private function payload(int $storeId): array
    {
        $store = Store::findOrFail($storeId);
        $set = OnlineStoreSetting::where('store_id', $storeId)->first();
        $branches = Branch::where('store_id', $storeId)->orderBy('id')->get(['id', 'name']);

        $time = fn ($t) => $t ? substr((string) $t, 0, 5) : '';

        // Row na ho to storefront jaisi hi default settings (store dikhti hai, delivery + pickup dono)
        $settings = [
            'exists' => (bool) $set,
            'is_online_enabled' => $set ? (bool) $set->is_online_enabled : true,
            'slug' => $set->slug ?? '',
            'display_name' => $set->display_name ?? '',
            'about' => $set->about ?? '',
            'phone' => $set->phone ?? '',
            'address' => $set->address ?? '',
            'open_time' => $time($set->open_time ?? null),
            'close_time' => $time($set->close_time ?? null),
            'accepts_delivery' => $set ? (bool) $set->accepts_delivery : true,
            'accepts_pickup' => $set ? (bool) $set->accepts_pickup : true,
            'min_order_amount' => $set ? (float) $set->min_order_amount : 0,
            'delivery_charge' => $set ? (float) $set->delivery_charge : 0,
            'free_delivery_above' => $set && $set->free_delivery_above !== null ? (float) $set->free_delivery_above : null,
            'prep_minutes' => $set ? (int) $set->prep_minutes : 30,
            'online_stock_percent' => $set ? (int) $set->online_stock_percent : 50,
        ];

        // Storefront par har branch ka apna link: "<slug>-b<branch_id>"
        $base = $settings['display_name'] ?: $store->name;
        $prefix = $settings['slug'] ?: (Str::slug((string) $base) ?: 'store');

        return [
            'store_name' => $store->name,
            'settings' => $settings,
            'listings' => $branches->map(fn ($b) => [
                'branch_id' => $b->id,
                'name' => $branches->count() > 1 ? "{$base} - {$b->name}" : $base,
                'slug' => "{$prefix}-b{$b->id}",
            ])->values(),
            'pincodes' => $this->pincodeList($storeId),
        ];
    }
}