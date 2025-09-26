<?php

namespace App\Models;

use App\Enums\AffiliatePayoutStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliatePayout extends Model
{
    use HasFactory;

    protected $table = 'affiliate_payouts';

    protected $fillable = [
        'affiliate_id',
        'payout_date',
        'amount',
        'status',
        'reference_number',
    ];

    protected $casts = [
        'payout_date' => 'datetime',
        'amount' => 'decimal:2',
        'status' => AffiliatePayoutStatus::class,
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}