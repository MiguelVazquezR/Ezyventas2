<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\InteractsWithMedia;

class GlobalProduct extends Model
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'description',
        'sku',
        'selling_price',
        'category_id',
        'brand_id',
        'measure_unit',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    /**
     * Obtiene la categoría a la que pertenece el producto.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Obtiene la marca a la que pertenece el producto.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
