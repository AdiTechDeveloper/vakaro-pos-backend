<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Copy to: database/migrations/  then run: php artisan migrate
return new class extends Migration
{
    private function hasIndex(string $table, string $index): bool
    {
        return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
    }

    public function up(): void
    {
        if (! $this->hasIndex('products', 'products_store_name_idx')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index(['store_id', 'name'], 'products_store_name_idx');
            });
        }

        if (! $this->hasIndex('inventories', 'inv_prod_branch_idx')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->index(['product_id', 'branch_id', 'deleted_at'], 'inv_prod_branch_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('products', 'products_store_name_idx')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_store_name_idx');
            });
        }

        if ($this->hasIndex('inventories', 'inv_prod_branch_idx')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->dropIndex('inv_prod_branch_idx');
            });
        }
    }
};