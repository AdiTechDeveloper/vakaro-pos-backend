<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    private const DEFAULTS = [
        'Electricity',
        'Rent',
        'Salary',
        'Tea & Coffee',
        'Transport',
        'Repairs & Maintenance',
        'Packaging',
        'Water & Internet',
        'Marketing',
        'Miscellaneous',
    ];

    private function storeId(Request $request): int
    {
        $user = $request->user();

        abort_unless(
            $user && in_array($user->role, ['admin', 'manager'], true),
            403,
            'You are not allowed to manage expense categories.'
        );
        abort_unless($user->store_id, 403, 'No store is assigned to this account.');

        return (int) $user->store_id;
    }

    private function nameRules(int $storeId, $ignoreId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:3',
                'max:80',
                Rule::unique('expense_categories', 'name')
                    ->where('store_id', $storeId)
                    ->ignore($ignoreId),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);

        // Pehli baar khali ho to ready-made categories bana do
        if (! ExpenseCategory::where('store_id', $storeId)->exists()) {
            foreach (self::DEFAULTS as $name) {
                ExpenseCategory::create([
                    'store_id' => $storeId,
                    'name' => $name,
                    'is_active' => true,
                ]);
            }
        }

        $categories = ExpenseCategory::where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return response()->json(['status' => true, 'categories' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);
        $data = $request->validate($this->nameRules($storeId));

        $category = ExpenseCategory::create([
            'store_id' => $storeId,
            'name' => trim($data['name']),
            'is_active' => true,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Category created.',
            'category' => $category,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $storeId = $this->storeId($request);
        $category = ExpenseCategory::where('store_id', $storeId)->findOrFail($id);

        $data = $request->validate($this->nameRules($storeId, $category->id));

        $category->update([
            'name' => trim($data['name']),
            'is_active' => $data['is_active'] ?? $category->is_active,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Category updated.',
            'category' => $category,
        ]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $storeId = $this->storeId($request);
        $category = ExpenseCategory::where('store_id', $storeId)->findOrFail($id);

        if ($category->expenses()->withTrashed()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'This category has expenses. Disable it instead of deleting.',
            ], 422);
        }

        $category->delete();

        return response()->json(['status' => true, 'message' => 'Category deleted.']);
    }
}