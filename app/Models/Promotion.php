<?php

namespace App\Models;

use App\Enums\PromotionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subscription_id',
        'description',
        'type',
        'start_date',
        'end_date',
        'is_active',
        'usage_limit',
        'priority',
        'is_exclusive',
    ];

    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'is_active' => 'boolean',
            'is_exclusive' => 'boolean',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(subscription::class);
    }
    
    public function rules(): HasMany
    {
        return $this->hasMany(PromotionRule::class);
    }
    
    public function effects(): HasMany
    {
        return $this->hasMany(PromotionEffect::class);
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class, 'promotion_transaction')
            ->withPivot('discount_applied') // Para acceder a columnas extra en la tabla pivote
            ->withTimestamps();
    }
}
