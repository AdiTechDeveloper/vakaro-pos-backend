<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MergeDuplicateCustomers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customers:merge-duplicates {--dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dry = $this->option('dry-run');

        $groups = DB::table('customers')
            ->select('store_id', 'mobile',
                DB::raw('MIN(id) as keep_id'),
                DB::raw('SUM(loyalty_points) as pts'),
                DB::raw('SUM(opening_balance) as wallet'))
            ->whereNotNull('store_id')
            ->groupBy('store_id', 'mobile')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicates.');

            return 0;
        }

        $childTables = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('COLUMN_NAME', 'customer_id')
            ->where('TABLE_NAME', '!=', 'customers')
            ->pluck('TABLE_NAME');

        $this->info('Groups: '.$groups->count().' | child tables: '.$childTables->implode(', '));

        try {
            DB::transaction(function () use ($groups, $childTables, $dry) {
                foreach ($groups as $g) {
                    $dupIds = DB::table('customers')
                        ->where('store_id', $g->store_id)->where('mobile', $g->mobile)
                        ->where('id', '!=', $g->keep_id)->pluck('id');

                    foreach ($childTables as $t) {
                        DB::table($t)->whereIn('customer_id', $dupIds)
                            ->update(['customer_id' => $g->keep_id]);
                    }

                    DB::table('customers')->where('id', $g->keep_id)
                        ->update(['loyalty_points' => $g->pts, 'opening_balance' => $g->wallet]);

                    DB::table('customers')->whereIn('id', $dupIds)->delete();

                    $this->line("store {$g->store_id} / {$g->mobile}: {$dupIds->count()} merged into #{$g->keep_id} (points {$g->pts}, wallet {$g->wallet})");
                }

                if ($dry) {
                    throw new \RuntimeException('DRY_RUN');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'DRY_RUN') {
                throw $e;
            }
            $this->warn('Dry run done. Everything rolled back.');
        }

        return 0;
    }
}
