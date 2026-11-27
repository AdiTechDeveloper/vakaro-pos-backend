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
use Illuminate\Support\Facades\Schema;
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

        // Text fields: har language ke letters + 0-9 + thode se symbols (frontend onlineValidation.js ke same rules)
        $data = $request->validate([
            'is_online_enabled' => ['required', 'boolean'],
            'slug' => [
                'nullable', 'string', 'min:3', 'max:50',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                'not_regex:/-b\d+$/', // "-b<number>" branch ke liye reserved hai
                Rule::unique('online_store_settings', 'slug')->ignore($existing?->id),
            ],
            'display_name' => [
                'nullable', 'string', 'min:3', 'max:60',
                'regex:/\A[\p{L}\p{M}0-9][\p{L}\p{M}0-9 .&\'-]*\z/u',
                'regex:/\p{L}/u',
                'not_regex:/ {2,}/',
            ],
            'about' => [
                'nullable', 'string', 'min:10', 'max:500',
                'regex:/\A[\p{L}\p{M}0-9][\p{L}\p{M}0-9 .,!?&\'():;-]*\z/u',
                'regex:/\p{L}/u',
                'not_regex:/ {2,}/',
            ],
            'phone' => ['nullable', 'string', 'regex:/\A[6-9][0-9]{9}\z/'],
            'address' => [
                'nullable', 'string', 'min:5', 'max:150',
                'regex:/\A[\p{L}\p{M}0-9][\p{L}\p{M}0-9 ,.\/#-]*\z/u',
                'regex:/\p{L}/u',
                'not_regex:/ {2,}/',
            ],
            'open_time' => ['nullable', 'required_with:close_time', 'date_format:H:i'],
            'close_time' => ['nullable', 'required_with:open_time', 'date_format:H:i', 'different:open_time'],
            'accepts_delivery' => ['required', 'boolean'],
            'accepts_pickup' => ['required', 'boolean'],
            'min_order_amount' => ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
            'delivery_charge' => ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
            'free_delivery_above' => ['nullable', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,2', 'gte:min_order_amount'],
            'prep_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'online_stock_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'max_qty_per_product' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ], [
            'slug.regex' => 'Store link can have only small letters, numbers and hyphens (for example: my-store).',
            'slug.not_regex' => 'Store link cannot end with -b and a number (that is used for branches).',
            'slug.unique' => 'This store link is already taken. Please choose another.',
            'display_name.regex' => 'Display name can have only letters, numbers, spaces and . & \' - (start with a letter or number, at least one letter).',
            'display_name.not_regex' => 'Display name cannot have two spaces in a row.',
            'about.regex' => 'About can have only letters, numbers, spaces and . , ! ? & \' ( ) : ; - (one paragraph, start with a letter or number).',
            'about.not_regex' => 'About cannot have two spaces in a row.',
            'address.regex' => 'Address can have only letters, numbers, spaces and , . / # - (start with a letter or number, at least one letter).',
            'address.not_regex' => 'Address cannot have two spaces in a row.',
            'phone.regex' => 'Enter a valid 10-digit mobile number that starts with 6, 7, 8 or 9.',
            'open_time.required_with' => 'Enter both opening and closing time, or leave both empty.',
            'close_time.required_with' => 'Enter both opening and closing time, or leave both empty.',
            'close_time.different' => 'Closing time must be different from the opening time.',
            'min_order_amount.decimal' => 'Minimum order can have up to 2 decimal places.',
            'delivery_charge.decimal' => 'Delivery charge can have up to 2 decimal places.',
            'free_delivery_above.decimal' => 'Free delivery limit can have up to 2 decimal places.',
            'free_delivery_above.gt' => 'Free delivery limit must be more than 0.',
            'free_delivery_above.gte' => 'Free delivery limit cannot be less than the minimum order.',
            'prep_minutes.min' => 'Prepare time must be at least 1 minute.',
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
                   'max_qty_per_product' => $blank($data['max_qty_per_product'] ?? null),
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
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
        ]);

        $raw = is_array($data['pincodes']) ? implode(' ', $data['pincodes']) : (string) $data['pincodes'];

        if (strlen($raw) > 2000) {
            return $this->fail('Too much text. You can add up to 200 pincodes at a time.', 422);
        }
        if (preg_match('/[^0-9\s,;]/', $raw)) {
            return $this->fail('Pincodes can have only digits, commas, spaces and new lines.', 422);
        }
        $list = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', $raw))));

        if (! $list) {
            return $this->fail('Enter at least one pincode.', 422);
        }
        if (count($list) > 200) {
            return $this->fail('You can add up to 200 pincodes at a time.', 422);
        }

        $invalid = array_values(array_filter($list, fn ($p) => ! preg_match('/^[1-9]\d{5}$/', $p)));
        if ($invalid) {
            return $this->fail('These are not valid 6-digit pincodes (they cannot start with 0): '.implode(', ', array_slice($invalid, 0, 5)), 422);
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
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
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

    // PUT /api/online/branches/{id}   body: { online_enabled: true|false }
    // Is branch ko online store par on / off karo. Off branch customers ko dikhti nahi, order bhi nahi leti.
    // Pehle se lagaye gaye orders par koi asar nahi (staff unhe POS me dekh aur poora kar sakta hai).
    public function updateBranch(Request $request, int $id): JsonResponse
    {
        [$storeId] = $this->onlineScope($request, true);

        $data = $request->validate([
            'online_enabled' => ['required', 'boolean'],
        ]);

        if (! Schema::hasColumn('branches', 'online_enabled')) {
            return $this->fail('Branch on/off is not set up yet. Please run the latest database migration.', 409);
        }

        $branch = Branch::where('store_id', $storeId)->find($id);

        if (! $branch) {
            return $this->fail('Branch not found.', 404);
        }

        $branch->online_enabled = (bool) $data['online_enabled'];
        $branch->save();

        $label = trim((string) $branch->name) ?: "Branch {$branch->id}";

        return response()->json([
            'status' => true,
            'message' => $branch->online_enabled
                ? "{$label} is now ON for online orders."
                : "{$label} is now OFF for online orders.",
            'data' => $this->payload($storeId),
        ]);
    }

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
        $branches = Branch::where('store_id', $storeId)->orderBy('id')->get();

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
               'max_qty_per_product' => $set && $set->max_qty_per_product !== null ? (int) $set->max_qty_per_product : null,
        ];

        // Storefront par har branch ka apna link: "<slug>-b<branch_id>"
        $base = $settings['display_name'] ?: $store->name;
        $prefix = $settings['slug'] ?: (Str::slug((string) $base) ?: 'store');

        return [
            'store_name' => $store->name,
            'settings' => $settings,
            'listings' => $branches->map(fn ($b) => [
                'branch_id' => $b->id,
                'branch_name' => $b->name,
                'name' => $branches->count() > 1 ? "{$base} - {$b->name}" : $base,
                'slug' => "{$prefix}-b{$b->id}",
                // Is branch ka online on/off (column na ho to on maano)
                'online_enabled' => (bool) ($b->online_enabled ?? true),
            ])->values(),
            'pincodes' => $this->pincodeList($storeId),
        ];
    }
}