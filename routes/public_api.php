<?php

use App\Http\Controllers\Api\DeliveryOrderController;
use App\Http\Controllers\Api\OnlineOrderController;
use App\Http\Controllers\Api\OnlineStoreController;
use App\Http\Controllers\Api\Public\GeocodeController;
use App\Http\Controllers\Api\Public\OnlineProductLimitController;
use App\Http\Controllers\Api\Public\StorefrontAddressController;
use App\Http\Controllers\Api\Public\StorefrontAuthController;
use App\Http\Controllers\Api\Public\StorefrontController;
use App\Http\Controllers\Api\Public\StorefrontOrderController;
use App\Http\Middleware\StorefrontAuth;
use Illuminate\Support\Facades\Route;

/*
 | THROTTLE: 'throttle:30,1' jaisa numeric throttle sab routes ka count ek hi counter mein jodta hai
 | (IP ke hisaab se). Isliye har throttle ko alag naam (teesra hissa, jaise ,sf-otp-send) diya hai,
 | taaki ek route ki requests doosre ko block na karein.
 |
 | Public storefront routes (bina login ke).
 | routes/api.php ke END mein ye line jodo:
 |     require __DIR__ . '/public_api.php';
 | (api.php ka '/api' prefix automatically lag jayega)
 */
Route::prefix('public')->middleware('throttle:120,1,sf-browse')->group(function () {
    Route::get('stores', [StorefrontController::class, 'stores']);
    Route::get('stores/{slug}', [StorefrontController::class, 'show']);
    Route::get('stores/{slug}/categories', [StorefrontController::class, 'categories']);
    Route::get('stores/{slug}/products', [StorefrontController::class, 'products']);
});

// Location: GPS se pincode / area, aur pincode se area (frontend: /api/public/geo/...)
Route::prefix('public/geo')->middleware('throttle:30,1,sf-geo')->group(function () {
    Route::get('reverse', [GeocodeController::class, 'reverse']);
    Route::get('pincode', [GeocodeController::class, 'pincode']);
});

// Storefront customer login (mobile + OTP). Sanctum / POS auth se alag.
Route::prefix('public/auth')->group(function () {
    Route::post('send-otp', [StorefrontAuthController::class, 'sendOtp'])->middleware('throttle:10,1,sf-otp-send');
    Route::post('verify-otp', [StorefrontAuthController::class, 'verifyOtp'])->middleware('throttle:20,1,sf-otp-verify');

    Route::middleware([StorefrontAuth::class, 'throttle:60,1,sf-auth'])->group(function () {
        Route::get('me', [StorefrontAuthController::class, 'me']);
        Route::post('logout', [StorefrontAuthController::class, 'logout']);
    });
});

// Logged-in customer ke addresses
Route::prefix('public/addresses')->middleware([StorefrontAuth::class, 'throttle:60,1,sf-addresses'])->group(function () {
    Route::get('/', [StorefrontAddressController::class, 'index']);
    Route::post('/', [StorefrontAddressController::class, 'store']);
    Route::put('{id}', [StorefrontAddressController::class, 'update'])->whereNumber('id');
    Route::delete('{id}', [StorefrontAddressController::class, 'destroy'])->whereNumber('id');
});

// Online orders (login chahiye)
Route::prefix('public/orders')->middleware([StorefrontAuth::class])->group(function () {
    Route::get('/', [StorefrontOrderController::class, 'index'])->middleware('throttle:60,1,sf-orders-read');
    Route::post('/', [StorefrontOrderController::class, 'store'])->middleware('throttle:30,1,sf-order-create');
    Route::get('{orderNo}', [StorefrontOrderController::class, 'show'])->middleware('throttle:60,1,sf-orders-read');
    Route::post('{orderNo}/cancel', [StorefrontOrderController::class, 'cancel'])->middleware('throttle:20,1,sf-order-cancel');
});

// Store ke staff ke liye online orders (POS login, Sanctum). URL: /api/online-orders
// "online_store" feature jis store ko superadmin ne chalu kiya ho sirf wahi use kar sakta hai.
Route::prefix('online-orders')
    ->middleware(['auth:sanctum', 'token.expiry', 'extend.token', 'api.auth.response', 'feature:online_store'])
    ->group(function () {
        Route::get('/', [OnlineOrderController::class, 'index']);
        Route::get('delivery-staff', [OnlineOrderController::class, 'deliveryStaff']);
        Route::get('{id}', [OnlineOrderController::class, 'show'])->whereNumber('id');
        Route::post('{id}/status', [OnlineOrderController::class, 'updateStatus'])->whereNumber('id');
        Route::post('{id}/assign', [OnlineOrderController::class, 'assign'])->whereNumber('id');
        Route::post('{id}/unassign', [OnlineOrderController::class, 'unassign'])->whereNumber('id');
        Route::post('{id}/resend-otp', [OnlineOrderController::class, 'resendOtp'])
            ->whereNumber('id')
            ->middleware('throttle:10,1,pos-otp-resend');
    });

// POS Admin: Online Store Settings + delivery pincodes. URL: /api/online/settings, /api/online/pincodes
Route::prefix('online')
    ->middleware(['auth:sanctum', 'token.expiry', 'extend.token', 'api.auth.response', 'feature:online_store'])
    ->group(function () {
        Route::get('settings', [OnlineStoreController::class, 'show']);
        Route::put('settings', [OnlineStoreController::class, 'update']);
        Route::get('pincodes', [OnlineStoreController::class, 'pincodes']);
        Route::post('pincodes', [OnlineStoreController::class, 'addPincodes']);
        Route::put('pincodes/{id}', [OnlineStoreController::class, 'updatePincode'])->whereNumber('id');
        Route::delete('pincodes/{id}', [OnlineStoreController::class, 'destroyPincode'])->whereNumber('id');
                Route::get('product-limits', [OnlineProductLimitController::class, 'index']);
        Route::put('product-limits/{productId}', [OnlineProductLimitController::class, 'update'])->whereNumber('productId');
    });

// Delivery person (role = delivery, ya is_delivery wala staff). URL: /api/delivery/...
// "feature:online_store" yahan nahi: delivery role ke apne features nahi hote. Store ka online_store feature
// DeliveryOrderController andar check karta hai. Baaki POS ke APIs delivery token ke liye BlockDeliveryRole se band hain.
Route::prefix('delivery')
    ->middleware(['auth:sanctum', 'token.expiry', 'extend.token', 'api.auth.response'])
    ->group(function () {
        Route::get('orders', [DeliveryOrderController::class, 'index']);
        Route::get('history', [DeliveryOrderController::class, 'history']);
        Route::post('duty', [DeliveryOrderController::class, 'duty']);
        Route::post('orders/{id}/resend-otp', [DeliveryOrderController::class, 'resendOtp'])
            ->whereNumber('id')
            ->middleware('throttle:10,1,delivery-otp-resend');
        Route::post('orders/{id}/delivered', [DeliveryOrderController::class, 'delivered'])
            ->whereNumber('id')
            ->middleware('throttle:30,1,delivery-otp-verify');
        Route::post('orders/{id}/failed', [DeliveryOrderController::class, 'failed'])->whereNumber('id');
    });