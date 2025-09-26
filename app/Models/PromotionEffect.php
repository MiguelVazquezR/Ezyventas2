<?php

namespace App\Models;

use App\Enums\PromotionEffectType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PromotionEffect extends Model
{
    use HasFactory;

    protected $fillable = ['promotion_id', 'type', 'value', 'itemable_id', 'itemable_type'];

    protected $casts = [
        'type' => PromotionEffectType::class,
        'value' => 'decimal:2'
    ];
    
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
    
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }
}