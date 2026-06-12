<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferralCode extends Model
{
    use HasFactory;

    protected $fillable = ['subscription_id', 'code', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ReferralUsage::class);
    }
}
