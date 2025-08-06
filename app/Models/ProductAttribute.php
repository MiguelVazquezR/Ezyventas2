<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends Model
{
    use HasFactory;

    protected $table = 'product_attributes';

    protected $fillable = [
        'product_id',
        'attribute_name',
        'attribute_value',
        'price_modifier',
        'current_stock',
        'sku_suffix',
    ];

    protected $casts = [
        'price_modifier' => 'decimal:2',
        'current_stock' => 'integer',
    ];

    /**
     * Obtiene el producto base al que pertenece este atributo.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}