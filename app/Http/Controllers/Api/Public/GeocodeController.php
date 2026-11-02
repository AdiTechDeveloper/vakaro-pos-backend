<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Location helpers for the storefront.
 *  - reverse(): GPS coordinates -> pincode + area   ("Use my current location")
 *  - pincode(): typed pincode   -> area + city      (checks the pincode really exists)
 *
 * Lookups run on the server (not in the browser) so that an API key, if we switch
 * provider later, never reaches the browser, and results can be cached.
 *
 * Providers today (both free, no key):
 *  - reverse(): OpenStreetMap Nominatim
 *  - pincode(): India Post (api.postalpincode.in), with Nominatim as backup
 * To move to Mappls / Ola Maps / Google later, only lookup() and lookupPincode() change.
 */
class GeocodeController extends Controller
{
    // GET /api/public/geo/reverse?lat=23.0225&lng=72.5714
    public function reverse(Request $request): JsonResponse
    {
        $v = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        // 3 decimals = about 110 m. Same cache entry for people standing close together.
        $lat = round((float) $v['lat'], 3);
        $lng = round((float) $v['lng'], 3);

        // A failed lookup returns null, and null is never kept in the cache, so it is retried next time.
        $data = Cache::remember("geo:reverse:{$lat}:{$lng}", now()->addDays(30), fn () => $this->lookup($lat, $lng));

        if (!$data) {
            return response()->json(['message' => 'Could not look up this location right now.'], 502);
        }

        return response()->json(['data' => $data]);
    }

    // GET /api/public/geo/pincode?pin=380015
    public function pincode(Request $request): JsonResponse
    {
        $v = $request->validate(['pin' => ['required', 'digits:6']]);
        $pin = $v['pin'];

        // Indian pincodes never start with 0 or 9 (e.g. 000000, 999999 are not real)
        if (! preg_match('/^[1-8][0-9]{5}$/', $pin)) {
            return response()->json(['message' => 'We could not find this pincode.'], 404);
        }

        // null (services down) is not cached, so the next request tries again
        $result = Cache::remember("geo:pin:{$pin}", now()->addDays(30), fn () => $this->lookupPincode($pin));

        if (($result['status'] ?? '') === 'not_found') {
            return response()->json(['message' => 'We could not find this pincode.'], 404);
        }

        if (isset($result['data'])) {
            return response()->json(['data' => $result['data']]);
        }

        // Lookup services are down / unsure. Do NOT accept a random pincode:
        // accept it only if one of our stores already delivers there (so it is known to be real).
        $known = DB::table('store_service_pincodes')->where('pincode', $pin)->exists();
        if ($known) {
            return response()->json(['data' => ['pincode' => $pin, 'area' => '', 'city' => '', 'state' => '']]);
        }

        return response()->json(['message' => 'We could not verify this pincode right now. Please try again in a moment.'], 503);
    }

    private function lookup(float $lat, float $lng): ?array
    {
        try {
            $res = Http::timeout(6)
                ->withHeaders($this->osmHeaders())
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'format' => 'jsonv2',
                    'lat' => $lat,
                    'lon' => $lng,
                    'zoom' => 18,
                    'addressdetails' => 1,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Reverse geocode failed: ' . $e->getMessage());

            return null;
        }

        if (!$res->ok()) {
            Log::warning('Reverse geocode HTTP ' . $res->status());

            return null;
        }

        $a = $res->json('address') ?? [];

        // Indian pincodes are 6 digits; OSM sometimes writes "380 015"
        $raw = preg_replace('/\s+/', '', (string) ($a['postcode'] ?? ''));
        $pincode = preg_match('/\d{6}/', $raw, $m) ? $m[0] : '';

        return [
            'pincode' => $pincode,
            'area' => $this->first($a, ['suburb', 'neighbourhood', 'city_district', 'quarter', 'residential', 'hamlet', 'village', 'road']),
            'city' => $this->first($a, ['city', 'town', 'municipality', 'county', 'state_district', 'village']),
            'state' => (string) ($a['state'] ?? ''),
        ];
    }

    /**
     * Returns ['status' => 'ok', 'data' => [...]]   pincode found
     *         ['status' => 'not_found']             a service clearly said this pincode does not exist
     *         null                                  could not find out (services down / unsure)
     */
    private function lookupPincode(string $pin): ?array
    {
        // 1) India Post: answers with a list of post offices for the pincode
        try {
            $res = Http::timeout(6)->acceptJson()->get("https://api.postalpincode.in/pincode/{$pin}");

            if ($res->ok()) {
                $row = $res->json('0') ?? [];

                if (($row['Status'] ?? '') === 'Success' && !empty($row['PostOffice'])) {
                    $po = $row['PostOffice'][0];

                    return [
                        'status' => 'ok',
                        'data' => [
                            'pincode' => $pin,
                            'area' => (string) ($po['Name'] ?? ''),
                            'city' => (string) ($po['District'] ?? ($po['Block'] ?? '')),
                            'state' => (string) ($po['State'] ?? ''),
                        ],
                    ];
                }

                // Only a clear "No records found" counts as invalid. Any other error = unsure, try the backup.
                if (stripos((string) ($row['Message'] ?? ''), 'no records') !== false) {
                    return ['status' => 'not_found'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('India Post pincode lookup failed: ' . $e->getMessage());
        }

        // 2) Backup: OpenStreetMap search by postal code
        try {
            $res = Http::timeout(6)
                ->withHeaders($this->osmHeaders())
                ->get('https://nominatim.openstreetmap.org/search', [
                    'format' => 'jsonv2',
                    'postalcode' => $pin,
                    'countrycodes' => 'in',
                    'addressdetails' => 1,
                    'limit' => 1,
                ]);

            if ($res->ok() && ($first = $res->json('0'))) {
                $a = $first['address'] ?? [];

                return [
                    'status' => 'ok',
                    'data' => [
                        'pincode' => $pin,
                        'area' => $this->first($a, ['suburb', 'neighbourhood', 'city_district', 'quarter', 'village', 'hamlet']),
                        'city' => $this->first($a, ['city', 'town', 'municipality', 'county', 'state_district']),
                        'state' => (string) ($a['state'] ?? ''),
                    ],
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('OSM pincode lookup failed: ' . $e->getMessage());
        }

        return null; // not sure: the caller only accepts it if a store already delivers to this pincode
    }

    private function osmHeaders(): array
    {
        return [
            // Nominatim requires an identifying User-Agent
            'User-Agent' => 'VakaroStore/1.0 (' . config('app.url') . ')',
            'Accept-Language' => 'en',
        ];
    }

    private function first(array $a, array $keys): string
    {
        foreach ($keys as $k) {
            if (!empty($a[$k])) {
                return (string) $a[$k];
            }
        }

        return '';
    }
}