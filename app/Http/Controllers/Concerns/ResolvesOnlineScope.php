<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Branch;
use Illuminate\Http\Request;

trait ResolvesOnlineScope
{
    /**
     * [storeId, branchIds] jo is login wale user ko dikhne chahiye.
     * - admin   : poora store ki saari branches
     * - manager / cashier : sirf apni branch(es)
     *
     * $adminOnly = true ho to sirf store admin (settings badalne ke liye).
     */
    protected function onlineScope(Request $request, bool $adminOnly = false): array
    {
        $user = $request->user();

        abort_unless(
            $user && in_array($user->role, ['admin', 'manager', 'cashier'], true),
            403,
            'You are not allowed to manage online orders.'
        );
        abort_unless($user->store_id, 403, 'No store is assigned to this account.');

        if ($adminOnly) {
            abort_unless($user->role === 'admin', 403, 'Only the store admin can change online store settings.');
        }

        $storeId = (int) $user->store_id;

        if ($user->role === 'admin') {
            $branchIds = Branch::where('store_id', $storeId)->pluck('id')->all();
        } else {
            $branchIds = $user->branches()->pluck('branches.id')->all();
            if (! empty($user->branch_id)) {
                $branchIds[] = $user->branch_id;
            }
        }

        $branchIds = array_values(array_unique(array_map('intval', array_filter($branchIds))));

        abort_unless(! empty($branchIds), 403, 'No branch is assigned to this account.');

        return [$storeId, $branchIds];
    }
}