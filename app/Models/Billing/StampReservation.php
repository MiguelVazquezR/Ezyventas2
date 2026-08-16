<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * StampReservation
 *
 * Created BEFORE calling the PAC, so:
 *  - For "normal" accounts it protects the shared-pool balance.
 *  - For both account types it provides idempotency via `customid` so a
 *    timeout can be retried with the same payload without duplicating or
 *    losing the stamping.
 *
 * Statuses:
 *  - held:          created, waiting to be resolved with the PAC
 *  - confirmed:     the PAC stamped successfully (or recovered via 307)
 *  - released:      freed because the attempt failed unambiguously
 *  - ambiguous:     timeout/network error — a background job is retrying
 *  - manual_review: automatic retries exhausted — an admin must decide
 *
 * NEVER auto-release an ambiguous reservation: if the PAC actually stamped,
 * a retry would generate a real duplicate (double spend).
 */
class StampReservation extends Model
{
    use HasFactory;

    protected $table = 'stamp_reservations';

    protected $fillable = [
        'fiscal_profile_id',
        'reference_type',
        'reference_id',
        'customid',
        'quantity',
        'status',
        'attempts',
        'confirmed_at',
        'released_at',
        'last_pac_response',
    ];

    protected function casts(): array
    {
        return [
            'quantity'           => 'integer',
            'attempts'           => 'integer',
            'confirmed_at'       => 'datetime',
            'released_at'        => 'datetime',
            'last_pac_response'  => 'array',
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

    /**
     * Polymorphic relation — normally an Invoice.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /*
    |--------------------------------------------------------------------------
    | Status helpers
    |--------------------------------------------------------------------------
    */

    public function isHeld(): bool
    {
        return $this->status === 'held';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isReleased(): bool
    {
        return $this->status === 'released';
    }

    public function isAmbiguous(): bool
    {
        return $this->status === 'ambiguous';
    }

    public function isManualReview(): bool
    {
        return $this->status === 'manual_review';
    }
}
