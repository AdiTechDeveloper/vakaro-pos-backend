<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\Public\StorefrontOrderController;
use App\Models\StorefrontCustomer;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** online:oversell-test ka helper: ek customer ka ek order (alag process). Seedha mat chalao. */
class OnlineOversellClient extends Command
{
    protected $signature = 'online:oversell-client {store} {product} {customer} {qty} {at} {mode=pickup}';

    protected $description = 'Internal: oversell test ka ek client';

    protected $hidden = true;

    public function handle(): int
    {
        $customer = StorefrontCustomer::findOrFail((int) $this->argument('customer'));

        // Sab clients ek hi pal par shuru hon
        $at = (float) $this->argument('at');
        while (microtime(true) < $at) {
            usleep(200);
        }

        $req = Request::create('/api/public/orders', 'POST', [
            'store' => $this->argument('store'),
            'mode' => $this->argument('mode'),
            'name' => $customer->name ?: 'Stress Test',
            'items' => [['product_id' => (int) $this->argument('product'), 'qty' => (float) $this->argument('qty')]],
        ]);
        $req->headers->set('Idempotency-Key', 'stress'.Str::random(24));
        $req->attributes->set('storefront_customer', $customer);

        try {
            $res = app(StorefrontOrderController::class)->store($req);
            $body = $res instanceof JsonResponse ? $res->getData(true) : [];
            $this->line('RESULT '.$res->getStatusCode().' '.($body['message'] ?? ''));
        } catch (\Throwable $e) {
            $code = $e instanceof \Illuminate\Validation\ValidationException ? 422 : 500;
            $this->line("RESULT {$code} ".Str::limit($e->getMessage(), 150));
        }

        return self::SUCCESS;
    }
}