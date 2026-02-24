<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductAttribute extends Model
{
    use HasFactory;

    protected $table = 'product_attributes';

    protected $fillable = [
        'product_id',
        'attributes', 
        'selling_price_modifier', 
        'sku_suffix',
        'global_product_id'
    ];

    protected $casts = [
        'attributes' => 'array',
        'selling_price_modifier' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_product_attribute')
            ->using(BranchProductAttribute::class)
            ->withPivot([
                'price_modifier',
                'current_stock',
                'reserved_stock',
                'location'
            ])
            ->withTimestamps();
    }
}