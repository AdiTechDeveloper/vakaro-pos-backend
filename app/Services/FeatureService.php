<?php

namespace App\Services;

use App\Models\StoreFeature;
use App\Models\User;
use App\Models\UserFeature;

class FeatureService
{
    public static function alwaysFeatures(): array
    {
        return array_keys(array_filter(config('features'), fn ($f) => ! empty($f['always'])));
    }

    public static function selectableFeatures(): array
    {
        return array_keys(array_filter(config('features'), fn ($f) => empty($f['always'])));
    }

    public static function effectiveFeatures(User $user): array
    {
        if ($user->role === 'superadmin') {
            return array_keys(config('features'));
        }

        if ($user->role === 'admin' || $user->role === 'manager') {
            return array_values(array_unique(array_merge(
                self::alwaysFeatures(),
                self::assignedFeatures($user)
            )));
        }

        return [];
    }

    public static function assignedFeatures(User $user): array
    {
        if ($user->role === 'admin') {
            return self::storeFeatures($user->store_id);
        }
        if ($user->role === 'manager') {
            return self::filterSelectable(
                UserFeature::where('user_id', $user->id)->pluck('feature_key')->toArray()
            );
        }

        return [];
    }

    public static function userHasFeature(User $user, string $featureKey): bool
    {
        return in_array($featureKey, self::effectiveFeatures($user), true);
    }

    public static function storeFeatures(int $storeId): array
    {
        return self::filterSelectable(
            StoreFeature::where('store_id', $storeId)->pluck('feature_key')->toArray()
        );
    }

    public static function setStoreFeatures(int $storeId, array $featureKeys): void
    {
        $valid = self::filterSelectable($featureKeys);

        StoreFeature::where('store_id', $storeId)->delete();
        foreach ($valid as $key) {
            StoreFeature::create(['store_id' => $storeId, 'feature_key' => $key]);
        }

        $managerIds = User::where('store_id', $storeId)->where('role', 'manager')->pluck('id');
        UserFeature::whereIn('user_id', $managerIds)->whereNotIn('feature_key', $valid)->delete();
    }

    public static function setUserFeatures(User $manager, array $featureKeys): void
    {
        $storeAllowed = self::storeFeatures($manager->store_id);
        $valid = array_values(array_intersect($featureKeys, $storeAllowed));

        UserFeature::where('user_id', $manager->id)->delete();
        foreach ($valid as $key) {
            UserFeature::create(['user_id' => $manager->id, 'feature_key' => $key]);
        }
    }

    private static function filterSelectable(array $keys): array
    {
        return array_values(array_intersect($keys, self::selectableFeatures()));
    }
}
