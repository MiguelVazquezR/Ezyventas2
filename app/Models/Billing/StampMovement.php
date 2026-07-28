<?php

namespace App\Models\Billing;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * StampMovement
 *
 * Immutable audit trail for every stamp movement — both entries
 * (purchases, initial deposits, manual additions) and exits
 * (invoice stamping, manual removals).
 *
 * The authoritative balance is always live from the PAC (SW Sapien).
 * This table provides a chronological ledger for the UI.
 *
 * @property int $id
 * @property int $fiscal_profile_id
 * @property string $type              'entry' | 'exit'
 * @property string $description
 * @property int $quantity
 * @property int $balance_after
 * @property int|null $reference_id
 * @property string|null $reference_type
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read FiscalProfile $fiscalProfile
 * @property-read Model|null $reference
 */
class StampMovement extends Model
{
    protected $table = 'stamp_movements';

    protected $fillable = [
        'fiscal_profile_id',
        'type',
        'description',
        'quantity',
        'balance_after',
        'reference_id',
        'reference_type',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity'     => 'integer',
            'balance_after' => 'integer',
            'metadata'     => 'array',
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
     * Polymorphic relation — points to StampPurchase, Invoice, etc.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Quick access to metadata values.
     */
    public function meta(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata, $key, $default);
    }
}
