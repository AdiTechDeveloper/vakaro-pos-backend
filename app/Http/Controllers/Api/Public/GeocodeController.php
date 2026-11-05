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
 *  - reverse(): GPS coordinates -> pincode + area + city (+ house / landmark when the map knows them)
 *  - pincode(): typed pincode   -> area + city      (checks the pincode really exists)
 *  - search():  typed text      -> list of places   (area, landmark or pincode search on the map)
 *
 * Lookups run on the server (not in the browser) so that an API key, if we switch
 * provider later, never reaches the browser, and results can be cached.
 *
 * Providers today (both free, no key):
 *  - reverse() / search(): OpenStreetMap Nominatim
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

        // 5 decimals = about 1 m. We return house number / landmark now, and those belong to ONE
        // building, so two people 100 m apart must not share a cache entry (they did at 3 decimals).
        // "v2" = new response shape (house + landmark); old cached entries are simply not used.
        $lat = round((float) $v['lat'], 5);
        $lng = round((float) $v['lng'], 5);

        // A failed lookup returns null, and null is never kept in the cache, so it is retried next time.
        $data = Cache::remember("geo:reverse:v2:{$lat}:{$lng}", now()->addDays(30), fn () => $this->lookup($lat, $lng));

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

    // GET /api/public/geo/search?q=vastrapur&lat=23.03&lng=72.52
    public function search(Request $r): JsonResponse
    {
        $q = trim((string) $r->query('q', ''));
        if (mb_strlen($q) < 3) {
            return response()->json(['data' => []]);
        }

        $lat = $r->query('lat');
        $lng = $r->query('lng');
        $key = 'geo:search:v2:' . md5(mb_strtolower($q) . '|' . round((float) $lat, 1) . '|' . round((float) $lng, 1));

        if ($hit = Cache::get($key)) {
            return response()->json(['data' => $hit]);
        }

        $params = [
            'q' => $q,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => 6,
            'countrycodes' => 'in',
        ];

        // Show places near the map first
        if (is_numeric($lat) && is_numeric($lng)) {
            $d = 0.5;
            $params['viewbox'] = ($lng - $d) . ',' . ($lat + $d) . ',' . ($lng + $d) . ',' . ($lat - $d);
        }

        // 1) Nominatim: exact words, good for areas, roads, pincodes and well-mapped places
        $rows = [];
        $failed = false;
        try {
            $res = Http::timeout(8)
                ->withHeaders($this->osmHeaders())
                ->get('https://nominatim.openstreetmap.org/search', $params);

            if ($res->ok()) {
                $rows = $res->json() ?? [];
            } else {
                $failed = true;
                Log::warning('Place search HTTP ' . $res->status());
            }
        } catch (\Throwable $e) {
            $failed = true;
            Log::warning('Place search failed: ' . $e->getMessage());
        }

        $data = collect($rows)->map(function ($p) {
            $parts = $this->placeParts($p['address'] ?? []);

            // Searching "Vatva" often gives no suburb key: the result's own name is the area
            if ($parts['area'] === '' && !empty($p['name'])) {
                $parts['area'] = (string) $p['name'];
            }

            // The result IS a building / society: its name is what goes in "Flat / Building name"
            if (($p['category'] ?? '') === 'building' && !empty($p['name'])) {
                $parts['house'] = (string) $p['name'];
            }

            return array_merge($parts, [
                'label' => $p['display_name'],
                'lat' => (float) $p['lat'],
                'lng' => (float) $p['lon'],
            ]);
        })->values()->all();

        // 2) Nothing found: try Photon. It is OpenStreetMap too, but forgives spelling mistakes
        //    and half-typed words ("lilamai corporate height"), which suits society / building names.
        if (!$data) {
            $data = $this->photonSearch($q, $lat, $lng);
        }

        if (!$data && $failed) {
            return response()->json(['data' => []], 502);
        }

        if ($data) {
            Cache::put($key, $data, now()->addDay());
        }

        return response()->json(['data' => $data]);
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

        $parts = $this->placeParts($res->json('address') ?? []);

        // The map gave no area name: use the name of the spot itself (a station, a market...)
        $name = trim((string) $res->json('name'));
        if ($parts['area'] === '' && $name !== '') {
            $parts['area'] = $name;
        }

        return $parts;
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

    /**
     * Fallback place search (Photon by Komoot, free, OpenStreetMap data, typo tolerant).
     * Public server is for light use. If traffic grows: host Photon yourself or switch to a paid
     * India provider (Mappls / Ola Maps / Google). Only this method and lookup() would change.
     */
    private function photonSearch(string $q, $lat, $lng): array
    {
        $params = ['q' => $q, 'limit' => 8, 'lang' => 'en'];
        if (is_numeric($lat) && is_numeric($lng)) {
            $params['lat'] = $lat; // results near the map come first
            $params['lon'] = $lng;
        }

        try {
            $res = Http::timeout(8)
                ->withHeaders($this->osmHeaders())
                ->get('https://photon.komoot.io/api/', $params);
        } catch (\Throwable $e) {
            Log::warning('Photon search failed: ' . $e->getMessage());

            return [];
        }

        if (!$res->ok()) {
            Log::warning('Photon search HTTP ' . $res->status());

            return [];
        }

        return collect($res->json('features') ?? [])
            ->filter(fn ($f) => (($f['properties']['countrycode'] ?? '') === 'IN') && isset($f['geometry']['coordinates'][1]))
            ->map(function ($f) {
                $p = $f['properties'];
                [$lngV, $latV] = $f['geometry']['coordinates'];

                $name = trim((string) ($p['name'] ?? ''));
                $key = (string) ($p['osm_key'] ?? '');

                $raw = preg_replace('/\s+/', '', (string) ($p['postcode'] ?? ''));
                $pincode = preg_match('/\d{6}/', $raw, $m) ? $m[0] : '';

                $area = (string) ($p['district'] ?? $p['locality'] ?? '');
                if ($area === '' && $key === 'place') {
                    $area = $name; // the result is an area itself (Vatva, Vastrapur...)
                }

                $labelParts = array_unique(array_filter([
                    $name,
                    $p['street'] ?? null,
                    $p['district'] ?? ($p['locality'] ?? null),
                    $p['city'] ?? ($p['county'] ?? null),
                    $p['state'] ?? null,
                    $pincode ?: null,
                ]));

                return [
                    'label' => implode(', ', $labelParts),
                    'lat' => (float) $latV,
                    'lng' => (float) $lngV,
                    'pincode' => $pincode,
                    'area' => $area,
                    'city' => (string) ($p['city'] ?? $p['county'] ?? ''),
                    'state' => (string) ($p['state'] ?? ''),
                    // A building / society name goes to "Flat / Building name"; a shop, school, station... is a landmark
                    'house' => $key === 'building' ? $name : '',
                    'landmark' => in_array($key, ['amenity', 'shop', 'tourism', 'office', 'leisure', 'railway', 'historic'], true) ? $name : '',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Turns a Nominatim "address" block into the fields our address form needs.
     * Used by both reverse() and search(), so a pin and a search result fill the form the same way.
     */
    private function placeParts(array $a): array
    {
        // Indian pincodes are 6 digits; OSM sometimes writes "380 015"
        $raw = preg_replace('/\s+/', '', (string) ($a['postcode'] ?? ''));
        $pincode = preg_match('/\d{6}/', $raw, $m) ? $m[0] : '';

        // House / building: only when the map really has it for this exact spot. Often empty in India.
        // OSM "building" tag is either a type word in small letters ("yes", "apartments", "commercial")
        // or a real name ("Lilamani Corporate Heights"). Only a name, which has a capital letter, is useful.
        $buildingName = (string) ($a['building'] ?? '');
        if (! preg_match('/\p{Lu}/u', $buildingName)) {
            $buildingName = '';
        }

        $house = trim(implode(' ', array_filter([
            (string) ($a['house_name'] ?? ''),
            $buildingName,
            (string) ($a['house_number'] ?? ''),
        ])));

        return [
            'pincode' => $pincode,
            'area' => $this->first($a, ['suburb', 'neighbourhood', 'city_district', 'quarter', 'residential', 'hamlet', 'village', 'road']),
            // The district (Ahmedabad) is a better "city" for a customer than a taluka (Vatva Taluka)
            'city' => $this->first($a, ['city', 'town', 'municipality', 'state_district', 'county', 'village']),
            'state' => (string) ($a['state'] ?? ''),
            'house' => $house,
            // A named place right here (shop, school, temple, station...) works as a "nearby landmark"
            'landmark' => $this->first($a, ['amenity', 'shop', 'tourism', 'office', 'leisure', 'historic', 'railway']),
        ];
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