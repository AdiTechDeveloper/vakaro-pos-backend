<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FeatureController extends Controller
{
    public function index()
    {
        return response()->json(['status' => true, 'data' => config('features')], 200);
    }

    public function myFeatures()
    {
        return response()->json(['status' => true, 'data' => FeatureService::effectiveFeatures(Auth::user())], 200);
    }

    public function getStoreFeatures($storeId)
    {
        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['message' => 'Store not found'], 404);
        }

        return response()->json(['status' => true, 'data' => FeatureService::storeFeatures($storeId)], 200);
    }

    public function setStoreFeatures(Request $request, $storeId)
    {
        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['message' => 'Store not found'], 404);
        }

        $request->validate(['features' => 'present|array', 'features.*' => 'string']);
        FeatureService::setStoreFeatures((int) $storeId, $request->features);

        return response()->json([
            'status' => true, 'message' => 'Store features updated.',
            'data' => FeatureService::storeFeatures((int) $storeId),
        ], 200);
    }

    public function getManagerFeatures($userId)
    {
        $authUser = Auth::user();
        $manager = User::find($userId);
        if (! $manager || $manager->role !== 'manager' || $manager->store_id !== $authUser->store_id) {
            return response()->json(['message' => 'Manager not found'], 404);
        }

        return response()->json([
            'status' => true,
            'data' => FeatureService::effectiveFeatures($manager),
            'available' => FeatureService::storeFeatures($authUser->store_id),
        ], 200);
    }

    public function setManagerFeatures(Request $request, $userId)
    {
        $authUser = Auth::user();
        if ($authUser->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $manager = User::find($userId);
        if (! $manager || $manager->role !== 'manager' || $manager->store_id !== $authUser->store_id) {
            return response()->json(['message' => 'Manager not found'], 404);
        }

        $request->validate(['features' => 'present|array', 'features.*' => 'string']);
        FeatureService::setUserFeatures($manager, $request->features);

        return response()->json([
            'status' => true, 'message' => 'Manager features updated.',
            'data' => FeatureService::effectiveFeatures($manager->fresh()),
        ], 200);
    }
}
