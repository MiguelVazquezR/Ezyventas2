<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProductComponent extends Model
{
    use HasFactory;

    protected $table = 'product_components';

    protected $fillable = [
        'composite_product_id',
        'componentable_id',
        'componentable_type',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    /**
     * El producto padre (El Kit o Combo)
     */
    public function compositeProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'composite_product_id');
    }

    /**
     * El producto componente (Puede ser un Product simple o un ProductAttribute/Variante)
     */
    public function componentable(): MorphTo
    {
        return $this->morphTo();
    }
}