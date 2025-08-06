<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name', 'description', 'sku', 'selling_price', 'cost_price',
        'current_stock', 'min_stock', 'max_stock', 'category_id', 'brand_id', 'branch_id', 'global_product_id',
        'measure_unit', 'currency', 'show_online', 'online_price', 'slug', 'delivery_days',
        'tags', 'is_featured', 'is_on_sale', 'sale_price', 'sale_start_date',
        'sale_end_date', 'weight', 'length', 'width', 'height',
        'requires_shipping', 'view_count', 'purchase_count',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'online_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'current_stock' => 'integer',
            'show_online' => 'boolean',
            'is_featured' => 'boolean',
            'is_on_sale' => 'boolean',
            'requires_shipping' => 'boolean',
            'sale_start_date' => 'datetime',
            'sale_end_date' => 'datetime',
            'tags' => 'array',
            'weight' => 'decimal:2',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */
    
    /**
     * Obtiene el producto global relacionado.
     */
    public function globalProduct(): BelongsTo
    {
        return $this->belongsTo(GlobalProduct::class);
    }
    
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
    
    /**
     * Obtiene la sucursal a la que pertenece el producto.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Obtiene los atributos (variaciones) del producto.
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }
    
    /**
     * Obtiene las reseñas del producto.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Obtiene todas las líneas de transacción para este producto.
     */
    public function transactionItems(): MorphMany
    {
        return $this->morphMany(TransactionItem::class, 'itemable');
    }
}