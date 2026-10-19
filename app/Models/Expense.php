<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    public const MODES = ['cash', 'bank', 'upi', 'card'];

    protected $fillable = [
        'expense_no',
        'store_id',
        'branch_id',
        'category_id',
        'expense_date',
        'amount',
        'payment_mode',
        'paid_to',
        'reference_no',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'expense_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    /**
     * Expense totals split into cash and bank (bank + upi + card).
     *
     * Cash in hand / bank balance ki calculation me isi ko minus karo:
     *   $exp = Expense::totalsByMode($storeId, $branchId);
     *   $cashInHand  -= $exp['cash'];
     *   $bankBalance -= $exp['bank'];
     *
     * Soft-deleted expenses automatically ginti se bahar hain, isliye edit/delete
     * par balance khud sahi ho jata hai (alag se adjust karne ki zaroorat nahi).
     */
    public static function totalsByMode(
        int $storeId,
        $branchId = null, // int | int[] | null
        ?string $from = null,
        ?string $to = null
    ): array {
        $query = static::query()
            ->where('store_id', $storeId)
            ->when($branchId, fn ($q) => is_array($branchId) ? $q->whereIn('branch_id', $branchId) : $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to));

        $cash = (float) (clone $query)->where('payment_mode', 'cash')->sum('amount');
        $bank = (float) (clone $query)->where('payment_mode', '!=', 'cash')->sum('amount');

        return [
            'cash' => round($cash, 2),
            'bank' => round($bank, 2),
            'total' => round($cash + $bank, 2),
        ];
    }
}