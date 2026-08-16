<?php

namespace App\Models\Billing;

use App\Enums\PacAccountStatus;
use App\Enums\PacAccountType;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PacAccount
 *
 * Represents a login account in the PAC (SW Sapien). Two types:
 *
 *  - 'subaccount': a dealer subaccount we provision ourselves under
 *    our master account. Stamps are assigned via the dealer API.
 *  - 'shared': an external account provided by the reseller (Conectia)
 *    shared by multiple subscribers' RFCs. Stamps are managed locally
 *    (wallet per fiscal profile) and never exposed to the subscriber.
 *
 * A single account may host multiple RFCs (fiscal profiles). The
 * account_type is administrative information — never shown to the
 * end customer.
 */
class PacAccount extends Model
{
    use HasFactory;

    protected $table = 'pac_accounts';

    protected $fillable = [
        'subscription_id',
        'is_shared',
        'provider',
        'account_type',
        'sw_user_id',
        'login_email',
        'password',
        'status',
        'requested_by_user_id',
        'activated_by_user_id',
        'requested_at',
        'activated_at',
        'admin_notes',
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
            'password'      => 'encrypted',
            'account_type'  => PacAccountType::class,
            'status'        => PacAccountStatus::class,
            'requested_at'  => 'datetime',
            'activated_at'  => 'datetime',
            'is_shared'     => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The subscription (tenant) that owns this PAC account.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The fiscal profiles (RFCs) hosted by this account.
     */
    public function fiscalProfiles(): HasMany
    {
        return $this->hasMany(FiscalProfile::class);
    }

    /**
     * The user who requested the account (if any).
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * The user who activated the account (if any).
     */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this account is a dealer subaccount.
     */
    public function isSubaccount(): bool
    {
        return $this->account_type === PacAccountType::SUBACCOUNT;
    }

    /**
     * Whether this account is an external shared account (Conectia).
     *
     * Shared accounts host RFCs of multiple subscriptions; their stamps
     * are managed locally (wallet) and never exposed to the subscriber.
     */
    public function isShared(): bool
    {
        return $this->account_type === PacAccountType::SHARED;
    }

    /**
     * Whether the account is fully active (credentials validated).
     */
    public function isActive(): bool
    {
        return $this->status === PacAccountStatus::ACTIVE;
    }

    /**
     * Whether the account was flagged as the platform-level shared account
     * (legacy boolean marker; kept for backwards compatibility).
     */
    public function isPlatformShared(): bool
    {
        return (bool) $this->is_shared;
    }

    /**
     * Scope: the active, shared external account of the platform.
     */
    public function scopeSharedActive(Builder $query): Builder
    {
        return $query->where('account_type', PacAccountType::SHARED)
            ->where('status', PacAccountStatus::ACTIVE);
    }

    /**
     * Whether the account has resolvable login credentials.
     */
    public function hasCredentials(): bool
    {
        return ! empty($this->login_email) && ! empty($this->password);
    }
}
