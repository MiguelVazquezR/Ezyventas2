<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'business_type',
        'subscription_id',
    ];

    /**
     * Obtiene la suscripción a la que pertenece la marca.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * Obtiene todos los productos que pertenecen a esta marca.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Obtiene los tipos de negocio asociados a esta marca.
     */
    public function businessTypes(): BelongsToMany
    {
        return $this->belongsToMany(BusinessType::class, 'brand_business_type');
    }
}