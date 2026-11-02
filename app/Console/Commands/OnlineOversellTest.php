<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\Public\StorefrontController;
use App\Http\Controllers\Api\Public\StorefrontOrderController;
use App\Models\StorefrontCustomer;
use App\Services\StorefrontCatalog;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Overselling ka asli test: alag-alag PHP processes (alag DB connections) ek hi waqt par
 * ek hi product ke liye order daalte hain. Jitna stock hai utne hi orders accept hone chahiye.
 *
 *   php artisan online:oversell-test <store-slug> <product_id> [--extra=5] [--qty=1] [--mode=pickup]
 *
 * DEV / TEST database par chalao: ye kuch asli online orders banata hai (end mein hata deta hai).
 * Stock ya inventory ko touch nahi karta.
 */
class OnlineOversellTest extends Command
{
    protected $signature = 'online:oversell-test
        {store : Store ka slug}
        {product : Product id}
        {--extra=5 : Available stock se kitne zyada customers ek saath aayein}
        {--qty=1 : Har customer kitni qty mangta hai}
        {--mode=pickup : pickup | delivery (delivery ke liye customer ka address chahiye, isliye pickup rakho)}
        {--keep : Test ke orders/customers end mein na hatao}
        {--force : Confirm mat poocho}';

    protected $description = 'Parallel requests se check karo ki online stock oversell nahi hota';

    private const MOBILE_PREFIX = '99990';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Ye test DB mein temporary online orders banayega. Dev/test DB hai?')) {
            return self::FAILURE;
        }

        $slug = (string) $this->argument('store');
        $productId = (int) $this->argument('product');
        $qty = (float) $this->option('qty');

        $listing = app(StorefrontController::class)->findStore($slug);
        $before = app(StorefrontCatalog::class)->sellables($listing, [$productId])[$productId] ?? null;

        if (! $before) {
            $this->error('Product is store mein nahi mila.');
            return self::FAILURE;
        }

        $capacity = (int) floor($before['available'] / $qty);
        if ($capacity < 1) {
            $this->error("Online stock available nahi hai ({$before['available']}). Pehle stock/allocation badhao.");
            return self::FAILURE;
        }

        $clients = $capacity + max(1, (int) $this->option('extra'));
        if ($clients > 80) {
            $this->error("{$clients} parallel clients bahut zyada hain. Kam online stock wala product chuno.");
            return self::FAILURE;
        }

        $this->info("{$before['name']}: online available = {$before['available']}, har customer {$qty} maangega.");
        $this->info("{$clients} customers EK SAATH aa rahe hain, sirf {$capacity} ko order milna chahiye...");

        // Test customers
        $customers = [];
        for ($i = 1; $i <= $clients; $i++) {
            $customers[] = StorefrontCustomer::firstOrCreate(
                ['mobile' => self::MOBILE_PREFIX.str_pad((string) $i, 5, '0', STR_PAD_LEFT)],
                ['name' => "Stress Test {$i}"]
            );
        }
        DB::disconnect(); // parent ka connection child ke saath share na ho

        // Sab children ko ek hi start-time do, taaki sach mein ek saath takrayein
        $at = microtime(true) + 3.0;
        $procs = [];
        foreach ($customers as $c) {
            $p = new Process([
                PHP_BINARY, base_path('artisan'), 'online:oversell-client',
                $slug, (string) $productId, (string) $c->id, (string) $qty, (string) $at, (string) $this->option('mode'),
            ], base_path());
            $p->setTimeout(120);
            $p->start();
            $procs[] = $p;
        }

        $codes = [];
        foreach ($procs as $p) {
            $p->wait();
            if (preg_match('/^RESULT (\d+) ?(.*)$/m', $p->getOutput(), $m)) {
                $codes[] = ['code' => (int) $m[1], 'msg' => trim($m[2])];
            } else {
                $codes[] = ['code' => 0, 'msg' => trim($p->getErrorOutput() ?: $p->getOutput())];
            }
        }

        $ok = count(array_filter($codes, fn ($r) => $r['code'] === 201));
        $conflict = count(array_filter($codes, fn ($r) => $r['code'] === 409));
        $other = array_filter($codes, fn ($r) => ! in_array($r['code'], [201, 409], true));

        $ids = array_map(fn ($c) => $c->id, $customers);
        $ordered = (float) DB::table('online_order_items as i')
            ->join('online_orders as o', 'o.id', '=', 'i.online_order_id')
            ->whereIn('o.storefront_customer_id', $ids)
            ->where('i.product_id', $productId)
            ->whereIn('o.status', ['placed', 'accepted', 'preparing', 'ready', 'out_for_delivery'])
            ->sum('i.qty');

        $after = app(StorefrontCatalog::class)->sellables($listing, [$productId])[$productId]['available'] ?? null;

        $this->table(['Result', 'Count'], [
            ['201 order mila', $ok],
            ['409 stock khatam (sahi reject)', $conflict],
            ['Kuch aur', count($other)],
        ]);
        foreach (array_slice($other, 0, 5) as $r) {
            $this->warn("  code {$r['code']}: {$r['msg']}");
        }
        $this->line("Total qty orders mein: {$ordered} | available ab: {$after} (pehle: {$before['available']})");

        if (! $this->option('keep')) {
            DB::table('online_orders')->whereIn('storefront_customer_id', $ids)->delete(); // items/events cascade
            StorefrontCustomer::whereIn('id', $ids)->delete();
            $this->line('Test orders/customers hata diye.');
        }

        $oversold = $ordered > $before['available'] + 1e-9;
        if ($oversold) {
            $this->error("FAIL: OVERSELL! {$ordered} qty bik gayi, jabki sirf {$before['available']} thi.");
            return self::FAILURE;
        }
        if (count($other) > 0) {
            $this->warn('Overselling nahi hui, lekin kuch requests 201/409 ke alawa aayi (upar dekho: store band, pickup off, etc.).');
            return self::FAILURE;
        }
        if ($ok !== $capacity) {
            $this->warn("Overselling nahi hui, lekin {$capacity} ki jagah {$ok} orders mile.");
            return self::FAILURE;
        }

        $this->info("PASS: theek {$capacity} orders mile, baaki {$conflict} ko stock khatam bataya gaya. Overselling nahi hui.");

        return self::SUCCESS;
    }
}