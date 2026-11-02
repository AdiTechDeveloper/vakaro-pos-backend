<?php

namespace App\Services;

use App\Http\Controllers\Api\SalesBillController;
use App\Models\OnlineOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Online order deliver hone par POS ka asli bill banata hai (stock katna, GST, loyalty, reports sab wahi).
 * Naya billing logic nahi likha: seedha SalesBillController::store() ko chalate hain, usi staff user ke naam se
 * jisne "delivered" dabaya. Idempotency key 'online-order-{id}' hai, isliye dobara chalane par duplicate bill nahi banta.
 */
class OnlineOrderBilling
{
    /** @return array{ok:bool, bill_id?:int, message?:string} */
    public function createBill(OnlineOrder $order, $staff): array
    {
        $order->loadMissing('items');

        try {
            $lines = [];
            foreach ($order->items as $item) {
                foreach ($this->allocate((int) $order->branch_id, $item) as $line) {
                    $lines[] = $line;
                }
            }
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $isDelivery = $order->mode === 'delivery';

        $payload = [
            'branch_id' => $order->branch_id,
            'lines' => $lines,
            'customer' => array_filter([
                'name' => $order->customer_name,
                'mobile' => $order->customer_mobile,
                'add1' => $isDelivery ? $order->addr_line1 : null,
                'add2' => $isDelivery ? $order->addr_line2 : null,
                'area' => $isDelivery ? $order->addr_landmark : null,
                'city' => $isDelivery ? $order->addr_city : null,
            ], fn ($v) => $v !== null && $v !== ''),
            // COD / pay at store: saaman dene par cash mila. Delivery fee POS bill mein nahi jodi
            // (POS bill sirf saaman ka hota hai), isliye cash_received saaman ke total jitna hai.
            'payment_type' => 'cash',
            'cash_received' => $order->subtotal,
            'online_received' => 0,
            'balance_return' => 0,
        ];

        $sub = Request::create('/api/sales-bills', 'POST', $payload);
        $sub->headers->set('Idempotency-Key', 'online-order-'.$order->id);
        $sub->setUserResolver(fn () => $staff);

        $response = app(SalesBillController::class)->store($sub);
        $body = $response->getData(true);

        $ok = ($body['status'] ?? $body['success'] ?? false) === true;
        $billId = data_get($body, 'data.id');

        if (! $ok || ! $billId) {
            return ['ok' => false, 'message' => $body['message'] ?? 'POS bill could not be created.'];
        }

        return ['ok' => true, 'bill_id' => (int) $billId];
    }

    /**
     * Ek item ke liye POS ke lines banata hai. Stock "lot" (batch + selling price) ke hisaab se baant-ta hai,
     * jo lot pehle bikta hai (kam expiry, phir purana) wahi pehle. Customer ne jo price pe order kiya wahi
     * bill mein jata hai; lot ka price alag ho to price override record hota hai (price-override report mein dikhega).
     *
     * @return array<int,array>
     */
    private function allocate(int $branchId, $item): array
    {
        $rows = DB::table('inventories')
            ->where('product_id', $item->product_id)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->whereRaw('(qty - sold_qty - COALESCE(expired_qty, 0)) > 0')
            ->where(function ($q) {
                $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString());
            })
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get(['id', 'batch_no', 'selling_price', 'qty', 'sold_qty', 'expired_qty']);

        // POS ek hi (batch, selling price) ki saari rows ek saath katta hai, isliye unhe ek lot maano
        $groups = [];
        foreach ($rows as $r) {
            $k = $r->batch_no.'|'.$r->selling_price;
            $avail = max(0, (float) $r->qty - (float) $r->sold_qty - (float) ($r->expired_qty ?? 0));
            if (! isset($groups[$k])) {
                $groups[$k] = ['id' => $r->id, 'price' => (float) $r->selling_price, 'avail' => 0.0];
            }
            $groups[$k]['avail'] += $avail;
        }

        $need = (float) $item->qty;
        $lines = [];

        foreach ($groups as $g) {
            if ($need <= 1e-9) {
                break;
            }
            $take = min($g['avail'], $need);
            if ($take <= 0) {
                continue;
            }

            $line = ['product_id' => $item->product_id, 'inventory_id' => $g['id'], 'qty' => round($take, 2)];

            if (abs($g['price'] - (float) $item->price) > 0.001) {
                $line['is_price_overridden'] = true;
                $line['selling_price'] = (float) $item->price;
                $line['original_price'] = $g['price'];
            }

            $lines[] = $line;
            $need = round($need - $take, 4);
        }

        if ($need > 1e-6) {
            $have = round((float) $item->qty - $need, 2);
            throw new \RuntimeException("Not enough stock in POS for {$item->name} (needed {$item->qty}, found {$have}). Update stock and try again.");
        }

        return $lines;
    }
}