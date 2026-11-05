
<?php

use App\Http\Controllers\Api\DeliveryLocationController;
use App\Http\Controllers\Api\DeliveryOrderController;
use App\Http\Controllers\Api\OnlineOrderController;
use App\Http\Controllers\Api\OnlineStoreController;
use App\Http\Controllers\Api\OnlineStoreLogoController;
use App\Http\Controllers\Api\Public\GeocodeController;
use App\Http\Controllers\Api\Public\OnlineProductLimitController;
use App\Http\Controllers\Api\Public\StorefrontAddressController;
use App\Http\Controllers\Api\Public\StorefrontAuthController;
use App\Http\Controllers\Api\Public\StorefrontController;
use App\Http\Controllers\Api\Public\StorefrontOrderController;
use App\Http\Controllers\Api\Public\StorefrontTrackController;
use App\Http\Middleware\StorefrontAuth;
use Illuminate\Support\Facades\Route;

// Public storefront routes
Route::prefix('public')->middleware('throttle:120,1,sf-browse')->group(function () {
    Route::get('stores', [StorefrontController::class, 'stores']);
    Route::get('stores/{slug}', [StorefrontController::class, 'show']);
    Route::get('stores/{slug}/categories', [StorefrontController::class, 'categories']);
    Route::get('stores/{slug}/products', [StorefrontController::class, 'products']);
});

// Location routes
Route::prefix('public/geo')->middleware('throttle:30,1,sf-geo')->group(function () {
    Route::get('reverse', [GeocodeController::class, 'reverse']);
    Route::get('pincode', [GeocodeController::class, 'pincode']);
    Route::get('search', [GeocodeController::class, 'search']);
});

// Customer authentication
Route::prefix('public/auth')->group(function () {
    Route::post('send-otp', [StorefrontAuthController::class, 'sendOtp'])->middleware('throttle:10,1,sf-otp-send');
    Route::post('verify-otp', [StorefrontAuthController::class, 'verifyOtp'])->middleware('throttle:20,1,sf-otp-verify');

    Route::middleware([StorefrontAuth::class, 'throttle:60,1,sf-auth'])->group(function () {
        Route::get('me', [StorefrontAuthController::class, 'me']);
        Route::post('logout', [StorefrontAuthController::class, 'logout']);
    });
});

// Customer addresses
Route::prefix('public/addresses')->middleware([StorefrontAuth::class, 'throttle:60,1,sf-addresses'])->group(function () {
    Route::get('/', [StorefrontAddressController::class, 'index']);
    Route::post('/', [StorefrontAddressController::class, 'store']);
    Route::put('{id}', [StorefrontAddressController::class, 'update'])->whereNumber('id');
    Route::delete('{id}', [StorefrontAddressController::class, 'destroy'])->whereNumber('id');
});

// Customer online orders
Route::prefix('public/orders')->middleware(StorefrontAuth::class)->group(function () {
    Route::get('/', [StorefrontOrderController::class, 'index'])->middleware('throttle:60,1,sf-orders-read');
    Route::post('/', [StorefrontOrderController::class, 'store'])->middleware('throttle:30,1,sf-order-create');
    Route::get('{orderNo}', [StorefrontOrderController::class, 'show'])->middleware('throttle:60,1,sf-orders-read');
    Route::get('{orderNo}/track', [StorefrontTrackController::class, 'show'])->middleware('throttle:60,1,sf-order-track');
    Route::post('{orderNo}/cancel', [StorefrontOrderController::class, 'cancel'])->middleware('throttle:20,1,sf-order-cancel');
});

// POS staff online orders
Route::prefix('online-orders')
    ->middleware(['auth:sanctum', 'token.expiry', 'extend.token', 'api.auth.response', 'feature:online_store'])
    ->group(function () {
        Route::get('/', [OnlineOrderController::class, 'index']);
        Route::get('delivery-staff', [OnlineOrderController::class, 'deliveryStaff']);
        Route::get('{id}', [OnlineOrderController::class, 'show'])->whereNumber('id');
        Route::post('{id}/status', [OnlineOrderController::class, 'updateStatus'])->whereNumber('id');
        Route::post('{id}/eta', [OnlineOrderController::class, 'updateEta'])->whereNumber('id');
        Route::post('{id}/assign', [OnlineOrderController::class, 'assign'])->whereNumber('id');
        Route::post('{id}/unassign', [OnlineOrderController::class, 'unassign'])->whereNumber('id');
        Route::post('{id}/resend-otp', [OnlineOrderController::class, 'resendOtp'])
            ->whereNumber('id')
            ->middleware('throttle:10,1,pos-otp-resend');
    });

// Online store settings
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

        // Store logo
        Route::get('logo', [OnlineStoreLogoController::class, 'show']);
        Route::post('logo', [OnlineStoreLogoController::class, 'store'])->middleware('throttle:20,1,online-logo');
        Route::delete('logo', [OnlineStoreLogoController::class, 'destroy']);
    });

// Delivery person routes
Route::prefix('delivery')
    ->middleware(['auth:sanctum', 'token.expiry', 'extend.token', 'api.auth.response'])
    ->group(function () {
        Route::get('orders', [DeliveryOrderController::class, 'index']);
        Route::get('history', [DeliveryOrderController::class, 'history']);
        Route::post('duty', [DeliveryOrderController::class, 'duty']);

        Route::post('location', [DeliveryLocationController::class, 'store'])
            ->middleware('throttle:30,1,delivery-location');

        Route::post('orders/{id}/resend-otp', [DeliveryOrderController::class, 'resendOtp'])
            ->whereNumber('id')
            ->middleware('throttle:10,1,delivery-otp-resend');

        Route::post('orders/{id}/delivered', [DeliveryOrderController::class, 'delivered'])
            ->whereNumber('id')
            ->middleware('throttle:30,1,delivery-otp-verify');

        Route::post('orders/{id}/failed', [DeliveryOrderController::class, 'failed'])->whereNumber('id');
    });
