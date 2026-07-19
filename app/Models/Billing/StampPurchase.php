<?php

namespace App\Models\Billing;

use App\Enums\StampAdjustmentType;
use App\Enums\StampPaymentMethod;
use App\Enums\StampPurchaseStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StampPurchase
 *
 * Audit trail for every stamp acquisition — purchases
 * (Mercado Pago / bank transfer) and manual adjustments
 * made by the superadmin.
 *
 * This is NOT a balance ledger. The authoritative balance
 * is always queried live from the PAC (SW Sapien).
 */
class StampPurchase extends Model
{
    use HasFactory;

    protected $table = 'stamp_purchases';

    protected $fillable = [
        'fiscal_profile_id',
        'requested_by_user_id',
        'stamp_quantity',
        'unit_price',
        'amount_total',
        'pricing_tier_id',
        'payment_method',
        'status',
        'mp_payment_id',
        'mp_preference_id',
        'proof_file_path',
        'proof_uploaded_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'rejection_reason',
        'pac_stamps_response_raw',
        'stamps_applied_at',
        'admin_note',
        'adjustment_type',
    ];

    protected function casts(): array
    {
        return [
            'stamp_quantity'          => 'integer',
            'unit_price'              => 'decimal:4',
            'amount_total'            => 'decimal:2',
            'payment_method'          => StampPaymentMethod::class,
            'status'                  => StampPurchaseStatus::class,
            'adjustment_type'         => StampAdjustmentType::class,
            'pac_stamps_response_raw' => 'array',
            'proof_uploaded_at'       => 'datetime',
            'reviewed_at'             => 'datetime',
            'stamps_applied_at'       => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function pricingTier(): BelongsTo
    {
        return $this->belongsTo(StampPricingTier::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeForFiscalProfile($query, int $fiscalProfileId)
    {
        return $query->where('fiscal_profile_id', $fiscalProfileId);
    }

    public function scopeAwaitingReview($query)
    {
        return $query->where('status', StampPurchaseStatus::AWAITING_REVIEW);
    }

    public function scopePending($query)
    {
        return $query->where('status', StampPurchaseStatus::PENDING);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isAwaitingReview(): bool
    {
        return $this->status === StampPurchaseStatus::AWAITING_REVIEW;
    }

    public function isStampsApplied(): bool
    {
        return $this->status === StampPurchaseStatus::STAMPS_APPLIED;
    }

    public function isManualAdjustment(): bool
    {
        return $this->payment_method === StampPaymentMethod::MANUAL_ADJUSTMENT;
    }

    public function isBankTransfer(): bool
    {
        return $this->payment_method === StampPaymentMethod::BANK_TRANSFER;
    }

    public function isMercadoPago(): bool
    {
        return $this->payment_method === StampPaymentMethod::MERCADOPAGO;
    }
}
