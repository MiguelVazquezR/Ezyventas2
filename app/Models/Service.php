<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'name', 'description', 'slug',
        'base_price', 'duration_estimate', 'show_online',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'show_online' => 'boolean',
    ];
    
    /**
     * Un servicio puede pertenecer a una categoría (ej. "Reparación de Hardware").
     * Nota: Puede reutilizar el modelo Category existente.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
    
    /**
     * Obtiene todas las veces que este servicio ha sido un item en una orden.
     */
    public function orderItems(): MorphMany
    {
        return $this->morphMany(ServiceOrderItem::class, 'itemable');
    }
}