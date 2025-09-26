<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferralCode extends Model
{
    use HasFactory;
    
    protected $table = 'referral_codes';

    protected $fillable = [
        'affiliate_id', 'code', 'description', 'discount_type', 'discount_value',
        'commission_type', 'commission_value', 'is_active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'commission_value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ReferralUsage::class);
    }
}