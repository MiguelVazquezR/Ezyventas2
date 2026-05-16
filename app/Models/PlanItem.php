<?php

namespace App\Models;

use App\Enums\PlanItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class PlanItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'type',
        'name',
        'description',
        'monthly_price',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'type' => PlanItemType::class,
        'monthly_price' => 'decimal:2',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    /**
     * Scope para manejar la búsqueda centralizada desde el modelo.
     * Mantiene el controlador limpio de lógica de base de datos.
     */
    public function scopeSearch(Builder $query, ?string $searchTerm): void
    {
        $query->when($searchTerm, function ($q, $term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('key', 'like', "%{$term}%")
              ->orWhere('description', 'like', "%{$term}%");
        });
    }
}