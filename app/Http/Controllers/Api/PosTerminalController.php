<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PosTerminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PosTerminalController extends Controller
{
    public function register(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'branch_id' => 'required|integer',
            'device_label' => 'nullable|string|max:100',
        ]);

        $branch = Branch::where('store_id', $user->store_id)->find($request->branch_id);

        if (! $branch) {
            return response()->json(['message' => 'Invalid branch for this store.'], 422);
        }

        if ($user->role === 'manager') {
            $allowedBranchIds = $user->branches()->pluck('branches.id')->toArray();
            if (! in_array((int) $request->branch_id, $allowedBranchIds, true)) {
                return response()->json(['message' => 'Unauthorized - not your branch.'], 403);
            }
        } elseif ($user->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $terminal = PosTerminal::create([
            'store_id' => $user->store_id,
            'branch_id' => $branch->id,
            'device_token' => Str::random(48),
            'device_label' => $request->device_label,
            'registered_by' => $user->id,
        ]);

        return response()->json([
            'status' => true,
            'device_token' => $terminal->device_token,
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
        ], 201);
    }

    public function resolve(Request $request)
    {
        $request->validate(['device_token' => 'required|string']);

        $terminal = PosTerminal::where('device_token', $request->device_token)
            ->where('is_active', true)
            ->first();

        if (! $terminal) {
            return response()->json(['message' => 'Device not registered'], 404);
        }

        $terminal->update(['last_used_at' => now()]);

        return response()->json([
            'status' => true,
            'branch_id' => $terminal->branch_id,
            'branch_name' => $terminal->branch->name,
            'store_id' => $terminal->store_id,
        ], 200);
    }

    public function index()
    {
        $user = Auth::user();
        $query = PosTerminal::where('store_id', $user->store_id)->with('branch');

        if ($user->role === 'manager') {
            $allowedBranchIds = $user->branches()->pluck('branches.id')->toArray();
            $query->whereIn('branch_id', $allowedBranchIds);
        }

        return response()->json(['status' => true, 'data' => $query->get()], 200);
    }

    public function destroy($id)
    {
        $user = Auth::user();
        $terminal = PosTerminal::where('store_id', $user->store_id)->find($id);

        if (! $terminal) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $terminal->delete();

        return response()->json(['status' => true, 'message' => 'Device removed'], 200);
    }
}
