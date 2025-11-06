<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends Model
{
    use HasFactory;

    protected $table = 'product_attributes';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'product_id',
        'attributes', // La nueva columna JSON
        'selling_price_modifier', // Nombre más claro
        'current_stock',
        'reserved_stock',
        'min_stock',
        'max_stock',
        'sku_suffix',
    ];

    /**
     * The attributes that should be cast.
     *
     * La "magia" está aquí: Laravel convierte automáticamente el JSON de la BD a un array de PHP y viceversa.
     */
    protected $casts = [
        'attributes' => 'array',
        'selling_price_modifier' => 'decimal:2',
        'current_stock' => 'decimal:1',
        'reserved_stock' => 'decimal:1',
        'min_stock' => 'decimal:2',
        'max_stock' => 'decimal:2',
    ];

    /**
     * Obtiene el stock disponible para la venta (físico - reservado).
     */
    protected function availableStock(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->current_stock - $this->reserved_stock,
        );
    }

    /**
     * Obtiene el producto base al que pertenece esta combinación de atributos.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}