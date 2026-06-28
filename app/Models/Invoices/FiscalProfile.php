<?php

namespace App\Models\Invoices;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FiscalProfile
 *
 * Represents one RFC (tax ID) under which a subscription can issue
 * CFDI invoices. A single subscription may have multiple fiscal
 * profiles — one per legal entity / razón social.
 *
 * Each profile is mapped to a SW Sapien sub-user account that the
 * PAC uses to isolate CSDs and stamping capacity per RFC.
 */
class FiscalProfile extends Model
{
    use HasFactory;

    protected $table = 'fiscal_profiles';

    protected $fillable = [
        'subscription_id',
        'rfc',
        'razon_social',
        'regimen_fiscal',
        'postal_code',
        'email',
        'password',
        'sw_user_id',
        'sw_account_email',
        'certificate_number',
        'valid_from',
        'valid_to',
        'cer_file_path',
        'key_file_path',
        'is_active',
    ];

    /**
     * Attributes that should be hidden from serialization.
     */
    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The subscription (tenant) that owns this fiscal profile.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to only active fiscal profiles.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this profile has been successfully linked to a SW Sapien
     * sub-user account.
     */
    public function hasSwSubaccount(): bool
    {
        return ! empty($this->sw_user_id);
    }

    /**
     * Whether this profile is ready to be used as an emitter in a CFDI.
     * Requires the profile to be active and linked to a PAC subaccount.
     */
    public function isReadyForInvoicing(): bool
    {
        return $this->is_active && $this->hasSwSubaccount();
    }
}
