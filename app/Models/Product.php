<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id',
        'sku',
        'barcode',
        'name',
        'brand_id',
        'category_id',
        'hsn_code',
        'gst_rate_id',
        'mrp',
        'selling_price',
        'cost_price',
        'created_by',
        'updated_by',
        'stock',
        'gst_inclusive',
        'is_price_override',
        'sale_type',
        'plu_code',
        'price_per_kg',
        'image',
        'description',
        'product_info',

        // Pharmacy columns
        'salt_composition',
        'manufacturer',
        'drug_schedule',
        'requires_prescription',
        'pack_type',
        'units_per_pack',
        'allow_loose',
        'loose_unit',
    ];

    // product_info = [{label, value}, ...] (Blinkit jaisi product details: Unit, Shelf Life, Storage Tips ...)
    protected $casts = [
        'product_info' => 'array',
    ];

    // JSON mein `image_url` (poora URL) bhi jaye, taaki admin list/edit seedha dikha sake
    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('storage/'.$this->image) : null;
    }

    // Max kitni photos ek product me ho sakti hain
    public const MAX_IMAGES = 4;

    /**
     * Saari photos (pehli = main photo). products.image hamesha pehli photo ka
     * path rakhta hai, taaki storefront / list / bulk upload pehle jaise chalein.
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function gstRate()
    {
        return $this->belongsTo(GstRate::class);
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class, 'product_id', 'id');
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'branch_id');
    }

    /**
     * Get the total available stock by summing inventory batches.
     */
    // public function getTotalStockAttribute()
    // {
    //     return $this->inventories()->sum(DB::raw('qty - sold_qty'));
    // }
}