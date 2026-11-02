<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\PrescriptionRegister;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PrescriptionRegisterController extends Controller
{
    private const TZ = 'Asia/Kolkata';

    public function index(Request $request)
    {
        $request->validate([
            'date_range' => 'nullable|in:today,yesterday,last_7_days,this_month,custom',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'schedule' => 'nullable|in:all,H,H1,X,none',
            'branch_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
        ]);

        $user = Auth::user();
        [$from, $to] = $this->resolveRange($request);

        $query = PrescriptionRegister::query()
            ->join('products', 'products.id', '=', 'prescription_registers.product_id')
            ->join('sales_bills', 'sales_bills.id', '=', 'prescription_registers.sales_bill_id')
            ->leftJoin('branches', 'branches.id', '=', 'prescription_registers.branch_id')
            ->leftJoin('users', 'users.id', '=', 'prescription_registers.dispensed_by')
            ->where('prescription_registers.store_id', $user->store_id)
            ->whereBetween('prescription_registers.dispensed_at', [$from, $to]);

        if ($user->role === 'manager') {
            $query->whereIn(
                'prescription_registers.branch_id',
                $user->branches()->pluck('branches.id')
            );
        }

        if ($request->filled('branch_id')) {
            $query->where('prescription_registers.branch_id', (int) $request->branch_id);
        }

        if ($request->filled('schedule') && $request->schedule !== 'all') {
            $query->where('prescription_registers.drug_schedule', $request->schedule);
        }

        if ($request->filled('search')) {
            $term = '%'.trim($request->search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('prescription_registers.patient_name', 'like', $term)
                    ->orWhere('prescription_registers.doctor_name', 'like', $term)
                    ->orWhere('prescription_registers.prescription_no', 'like', $term)
                    ->orWhere('prescription_registers.batch_no', 'like', $term)
                    ->orWhere('products.name', 'like', $term)
                    ->orWhere('sales_bills.bill_no', 'like', $term);
            });
        }

        $rows = $query
            ->orderByDesc('prescription_registers.dispensed_at')
            ->orderByDesc('prescription_registers.id')
            ->limit(5000)
            ->get([
                'prescription_registers.*',
                'products.name as product_name',
                'products.salt_composition',
                'sales_bills.bill_no',
                'branches.name as branch_name',
                'users.name as dispensed_by_name',
            ]);

        $data = $rows->map(fn ($r) => [
            'id' => $r->id,
            'dispensed_at' => $r->dispensed_at
                ? $r->dispensed_at->copy()->setTimezone(self::TZ)->format('d-m-Y H:i')
                : null,
            'bill_no' => $r->bill_no,
            'branch_name' => $r->branch_name,
            'product_name' => $r->product_name,
            'salt_composition' => $r->salt_composition,
            'drug_schedule' => $r->drug_schedule,
            'batch_no' => $r->batch_no,
            'expiry_date' => $r->expiry_date ? $r->expiry_date->format('d-m-Y') : null,
            'qty' => (float) $r->qty,
            'patient_name' => $r->patient_name,
            'patient_age' => $r->patient_age,
            'patient_address' => $r->patient_address,
            'doctor_name' => $r->doctor_name,
            'doctor_reg_no' => $r->doctor_reg_no,
            'prescription_no' => $r->prescription_no,
            'prescription_date' => $r->prescription_date ? $r->prescription_date->format('d-m-Y') : null,
            'dispensed_by_name' => $r->dispensed_by_name,
        ]);

        $store = Store::find($user->store_id);

        return response()->json([
            'status' => true,
            'store' => [
                'name' => $store->name ?? '',
                'drug_licence_no' => $store->drug_licence_no ?? null,
                'pharmacist_name' => $store->pharmacist_name ?? null,
                'pharmacist_reg_no' => $store->pharmacist_reg_no ?? null,
            ],
            'summary' => [
                'total_entries' => $data->count(),
                'total_qty' => round((float) $rows->sum('qty'), 3),
                'unique_patients' => $rows
                    ->map(fn ($r) => strtolower(trim($r->patient_name)))
                    ->unique()
                    ->count(),
                'h1_entries' => $rows->whereIn('drug_schedule', ['H1', 'X'])->count(),
            ],
            'rows' => $data->values(),
        ]);
    }

    private function resolveRange(Request $request): array
    {
        $now = Carbon::now(self::TZ);

        switch ($request->input('date_range', 'this_month')) {
            case 'today':
                $from = $now->copy()->startOfDay();
                $to = $now->copy()->endOfDay();
                break;
            case 'yesterday':
                $from = $now->copy()->subDay()->startOfDay();
                $to = $now->copy()->subDay()->endOfDay();
                break;
            case 'last_7_days':
                $from = $now->copy()->subDays(6)->startOfDay();
                $to = $now->copy()->endOfDay();
                break;
            case 'custom':
                $from = Carbon::parse($request->input('date_from') ?? $now->toDateString(), self::TZ)->startOfDay();
                $to = Carbon::parse($request->input('date_to') ?? $now->toDateString(), self::TZ)->endOfDay();
                break;
            default: // this_month
                $from = $now->copy()->startOfMonth();
                $to = $now->copy()->endOfDay();
        }

        $appTz = config('app.timezone');

        return [$from->setTimezone($appTz), $to->setTimezone($appTz)];
    }
}
