<?php

namespace App\Models;

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
        'min_stock', // Añadido para consistencia
        'max_stock', // Añadido para consistencia
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
        'current_stock' => 'integer',
        'min_stock' => 'integer',
        'max_stock' => 'integer',
    ];

    /**
     * Obtiene el producto base al que pertenece esta combinación de atributos.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}