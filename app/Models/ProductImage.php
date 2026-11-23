<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'sort_order'];

    // JSON me poora URL (`url`) bhi jaye, Product::image_url jaisa
    protected $appends = ['url'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->path);
    }
}