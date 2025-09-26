<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BusinessType extends Model
{
    use HasFactory;

    protected $table = 'business_types';

    protected $fillable = ['name'];

    /**
     * Obtiene todas las marcas asociadas con este tipo de negocio.
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'brand_business_type');
    }
}