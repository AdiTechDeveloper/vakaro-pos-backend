<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ek product ki max 4 photos. Sirf relative path (jaise "products/1/abc.jpg", disk: public).
     * Pehli photo (sort_order sabse chhota) main hai aur wahi products.image me bhi rehti hai.
     */
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('path');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Purani single image (products.image) ko main photo bana ke copy karo
        DB::table('products')
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->orderBy('id')
            ->select('id', 'image')
            ->each(function ($p) {
                DB::table('product_images')->insert([
                    'product_id' => $p->id,
                    'path' => $p->image,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};