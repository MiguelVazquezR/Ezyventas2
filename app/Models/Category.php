<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'business_type',
        'subscription_id',
    ];

    /**
     * Obtiene la suscripción a la que pertenece la categoría.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * Obtiene todos los productos que pertenecen a esta categoría.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}