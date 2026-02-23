<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ServiceVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'name',
        'price',
        'duration_estimate',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    /**
     * Obtiene el servicio base al que pertenece esta variante.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Permite que esta variante sea agregada a una orden de servicio o venta,
     * utilizando tu arquitectura polimórfica existente.
     */
    public function orderItems(): MorphMany
    {
        // Asumiendo que tu tabla de items tiene itemable_id y itemable_type
        // Puedes referenciar ServiceOrderItem o TransactionItem según tu esquema
        return $this->morphMany(TransactionItem::class, 'itemable'); 
    }
}