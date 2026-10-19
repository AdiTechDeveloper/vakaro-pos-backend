<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    /**
     * [storeId, managerBranchIds]
     * - admin   : poora store, managerBranchIds = null (branch optional filter)
     * - manager : sirf apni branch(es), managerBranchIds = [ids]
     */
    private function scope(Request $request): array
    {
        $user = $request->user();

        abort_unless(
            $user && in_array($user->role, ['admin', 'manager'], true),
            403,
            'You are not allowed to manage expenses.'
        );
        abort_unless($user->store_id, 403, 'No store is assigned to this account.');

        $managerBranchIds = null;

        if ($user->role === 'manager') {
            $managerBranchIds = $this->managerBranchIds($user);
            abort_unless(! empty($managerBranchIds), 403, 'No branch is assigned to this account.');
        }

        return [(int) $user->store_id, $managerBranchIds];
    }

    /**
     * Manager ki branches alag-alag tareeke se store ho sakti hain, isliye sab try karte hain.
     * Jo tareeka aapke project me hai wo mil jayega; baaki silently skip.
     */
    private function managerBranchIds($user): array
    {
        $ids = [];

        // 1) users table me single column
        if (! empty($user->branch_id)) {
            $ids[] = $user->branch_id;
        }

        // 2) array / JSON / comma separated column
        if (! empty($user->branch_ids)) {
            $raw = $user->branch_ids;

            if ($raw instanceof \Illuminate\Support\Collection) {
                $raw = $raw->all();
            } elseif (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : explode(',', $raw);
            }

            $ids = array_merge($ids, (array) $raw);
        }

        // 3) relation: branches() (hasMany / belongsToMany)
        if (method_exists($user, 'branches')) {
            try {
                $ids = array_merge($ids, $user->branches()->get()->pluck('id')->all());
            } catch (\Throwable $e) {
                // relation is not usable, skip
            }
        }

        // 4) relation: branch() (belongsTo)
        if (method_exists($user, 'branch')) {
            try {
                if ($user->branch) {
                    $ids[] = $user->branch->id;
                }
            } catch (\Throwable $e) {
                // skip
            }
        }

        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }

    /**
     * Admin: jo branch bheji wahi (ya null = store level).
     * Manager: bheji hui branch agar uski apni hai, warna uski pehli branch.
     */
    private function resolveBranchId($requested, ?array $managerBranchIds): ?int
    {
        if ($managerBranchIds) {
            return in_array((int) $requested, $managerBranchIds, true)
                ? (int) $requested
                : $managerBranchIds[0];
        }

        return $requested ? (int) $requested : null;
    }

    private function scoped(int $storeId, ?array $managerBranchIds)
    {
        return Expense::query()
            ->where('store_id', $storeId)
            ->when($managerBranchIds, fn ($q) => $q->whereIn('branch_id', $managerBranchIds));
    }

    private function rules(int $storeId): array
    {
        return [
            // before_or_equal:tomorrow -> server UTC me ho aur user IST me, to subah ki entry reject na ho
            'expense_date' => ['required', 'date', 'before_or_equal:tomorrow'],
            'category_id' => [
                'required',
                Rule::exists('expense_categories', 'id')->where('store_id', $storeId),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'payment_mode' => ['required', Rule::in(Expense::MODES)],
            'paid_to' => ['nullable', 'string', 'max:120'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:300'],
            // Agar branches table me store_id hai to yahan ->where('store_id', $storeId) bhi laga do
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        [$storeId, $managerBranchIds] = $this->scope($request);

        $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'payment_mode' => ['nullable', Rule::in(Expense::MODES)],
            'category_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $rows = $this->scoped($storeId, $managerBranchIds)
            ->with('category:id,name')
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('expense_date', '>=', $request->from_date))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('expense_date', '<=', $request->to_date))
            ->when($request->filled('payment_mode'), fn ($q) => $q->where('payment_mode', $request->payment_mode))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->filled('branch_id'), function ($q) use ($request, $managerBranchIds) {
                // manager sirf apni branches me filter kar sakta hai
                if ($managerBranchIds && ! in_array((int) $request->branch_id, $managerBranchIds, true)) {
                    return $q->whereRaw('1 = 0');
                }
                return $q->where('branch_id', $request->branch_id);
            })
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit(5000)
            ->get();

        $cash = (float) $rows->where('payment_mode', 'cash')->sum('amount');
        $total = (float) $rows->sum('amount');

        return response()->json([
            'status' => true,
            'data' => $rows,
            'summary' => [
                'total' => round($total, 2),
                'cash' => round($cash, 2),
                'bank' => round($total - $cash, 2),
                'count' => $rows->count(),
            ],
        ]);
    }

    public function show(Request $request, $id): JsonResponse
    {
        [$storeId, $managerBranchIds] = $this->scope($request);

        $expense = $this->scoped($storeId, $managerBranchIds)
            ->with('category:id,name')
            ->findOrFail($id);

        return response()->json(['status' => true, 'data' => $expense]);
    }

    public function store(Request $request): JsonResponse
    {
        [$storeId, $managerBranchIds] = $this->scope($request);

        $data = $request->validate($this->rules($storeId));

        $category = ExpenseCategory::where('store_id', $storeId)->findOrFail($data['category_id']);
        if (! $category->is_active) {
            throw ValidationException::withMessages([
                'category_id' => 'This category is disabled. Enable it or choose another one.',
            ]);
        }

        $branchId = $this->resolveBranchId($data['branch_id'] ?? null, $managerBranchIds);

        $expense = DB::transaction(function () use ($data, $storeId, $branchId, $request) {
            $expense = Expense::create([
                'store_id' => $storeId,
                'branch_id' => $branchId,
                'category_id' => $data['category_id'],
                'expense_date' => $data['expense_date'],
                'amount' => $data['amount'],
                'payment_mode' => $data['payment_mode'],
                'paid_to' => $data['paid_to'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $expense->update([
                'expense_no' => 'EXP-' . str_pad((string) $expense->id, 6, '0', STR_PAD_LEFT),
            ]);

            return $expense;
        });

        return response()->json([
            'status' => true,
            'message' => 'Expense created.',
            'data' => $expense->load('category:id,name'),
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        [$storeId, $managerBranchIds] = $this->scope($request);

        $expense = $this->scoped($storeId, $managerBranchIds)->findOrFail($id);

        $data = $request->validate($this->rules($storeId));

        // Category badal rahi ho to naya category active hona chahiye
        if ((int) $data['category_id'] !== (int) $expense->category_id) {
            $category = ExpenseCategory::where('store_id', $storeId)->findOrFail($data['category_id']);
            if (! $category->is_active) {
                throw ValidationException::withMessages([
                    'category_id' => 'This category is disabled. Enable it or choose another one.',
                ]);
            }
        }

        $payload = [
            'category_id' => $data['category_id'],
            'expense_date' => $data['expense_date'],
            'amount' => $data['amount'],
            'payment_mode' => $data['payment_mode'],
            'paid_to' => $data['paid_to'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'note' => $data['note'] ?? null,
            'updated_by' => $request->user()->id,
        ];

        // Branch tabhi badlegi jab request me aayi ho; manager apni branches se bahar nahi ja sakta
        if (! empty($data['branch_id'])) {
            $payload['branch_id'] = $this->resolveBranchId($data['branch_id'], $managerBranchIds);
        }

        $expense->update($payload);

        return response()->json([
            'status' => true,
            'message' => 'Expense updated.',
            'data' => $expense->fresh()->load('category:id,name'),
        ]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        [$storeId, $managerBranchIds] = $this->scope($request);

        $expense = $this->scoped($storeId, $managerBranchIds)->findOrFail($id);
        $expense->delete(); // soft delete -> cash/bank totals se apne aap bahar

        return response()->json(['status' => true, 'message' => 'Expense deleted.']);
    }

    /**
     * Dashboard / reports ke liye: GET /api/expenses/summary?from_date=&to_date=
     */
    public function summary(Request $request): JsonResponse
    {
        [$storeId, $managerBranchIds] = $this->scope($request);

        $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $branchId = $managerBranchIds
            ?: ($request->filled('branch_id') ? (int) $request->branch_id : null);

        $totals = Expense::totalsByMode($storeId, $branchId, $request->from_date, $request->to_date);

        $byCategory = Expense::query()
            ->where('expenses.store_id', $storeId)
            ->when($branchId, fn ($q) => is_array($branchId) ? $q->whereIn('expenses.branch_id', $branchId) : $q->where('expenses.branch_id', $branchId))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('expenses.expense_date', '>=', $request->from_date))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('expenses.expense_date', '<=', $request->to_date))
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->groupBy('expenses.category_id', 'expense_categories.name')
            ->orderByDesc(DB::raw('SUM(expenses.amount)'))
            ->get([
                'expenses.category_id',
                'expense_categories.name',
                DB::raw('SUM(expenses.amount) as amount'),
            ]);

        return response()->json([
            'status' => true,
            'totals' => $totals,
            'by_category' => $byCategory,
        ]);
    }
}