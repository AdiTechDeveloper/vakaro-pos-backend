<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Store ka logo: admin upload karta hai, online store ke store card par dikhta hai.
 * URL: /api/online/logo  (GET, POST, DELETE). Sirf store ka admin.
 *
 * Logo online_store_settings.logo me rakha jata hai (wo column pehle se hai) aur storage ke andar ka
 * relative path hota hai, jaise "online-logos/1/abc.png" (products ki image jaisa).
 */
class OnlineStoreLogoController extends Controller
{
    // GET /api/online/logo
    public function show(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);

        return response()->json(['status' => true, 'data' => ['logo' => $this->urlOf($this->current($storeId))]]);
    }

    // POST /api/online/logo   (multipart: logo = image file)
    public function store(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);

        $request->validate([
            // SVG jaan-boojh ke nahi: usme script chhup sakti hai
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=100,min_height=100,max_width=4000,max_height=4000'],
        ], [
            'logo.mimes' => 'Please use a JPG, PNG or WebP image.',
            'logo.max' => 'The logo must be 2 MB or smaller.',
            'logo.dimensions' => 'The logo must be at least 100 x 100 pixels (and not bigger than 4000 x 4000).',
        ]);

        // Settings row nahi hai to logo kahan likhein? Pehle ek baar settings save karni hongi.
        if (! DB::table('online_store_settings')->where('store_id', $storeId)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Please save the online store settings once first, then upload the logo.',
            ], 422);
        }

        $file = $request->file('logo');
        $name = Str::uuid()->toString().'.'.($file->extension() ?: 'png');
        $stored = $file->storeAs("online-logos/{$storeId}", $name, 'public'); // "online-logos/1/abc.png"

        $old = $this->current($storeId);
        DB::table('online_store_settings')->where('store_id', $storeId)->update(['logo' => $stored]);
        $this->deleteFile($old);

        return response()->json(['status' => true, 'data' => ['logo' => $this->urlOf($stored)]]);
    }

    // DELETE /api/online/logo
    public function destroy(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);

        $old = $this->current($storeId);
        DB::table('online_store_settings')->where('store_id', $storeId)->update(['logo' => null]);
        $this->deleteFile($old);

        return response()->json(['status' => true, 'data' => ['logo' => null]]);
    }

    /**
     * Logged-in admin ka store. ⚠ Ye maan kar chalta hai ki user ke paas store_id hai.
     * Tumhare OnlineStoreController::show() me store jis tarah nikalta hai, wahi yahan rakho agar alag ho.
     */
    private function storeId(Request $request): int
    {
        $user = $request->user();

        abort_unless($user && $user->role === 'admin', 403, 'Only the store admin can change the logo.');

        $id = (int) ($user->store_id ?? 0);
        abort_if($id <= 0, 422, 'Your account is not linked to a store.');

        return $id;
    }

    private function current(int $storeId): ?string
    {
        return DB::table('online_store_settings')->where('store_id', $storeId)->value('logo');
    }

    // Saved value -> poora URL (admin preview ke liye). Purana poora URL ho to waisa hi.
    private function urlOf(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        $rel = ltrim($value, '/');
        if (str_starts_with($rel, 'storage/')) {
            $rel = substr($rel, 8);
        }

        return url('/storage/'.$rel);
    }

    // Sirf apne online-logos folder ki file hatate hain, kisi aur ki nahi
    private function deleteFile(?string $value): void
    {
        if (! $value || preg_match('#^https?://#i', $value)) {
            return;
        }

        $rel = ltrim($value, '/');
        if (str_starts_with($rel, 'storage/')) {
            $rel = substr($rel, 8);
        }

        if (str_starts_with($rel, 'online-logos/')) {
            Storage::disk('public')->delete($rel);
        }
    }
}