<?php

namespace App\Models\Billing;

use App\Models\Billing\StampMovement;
use App\Models\Subscription;
use App\Services\Billing\WalletService;
use App\Services\SW\SWUserService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

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
class FiscalProfile extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $table = 'fiscal_profiles';

    protected $fillable = [
        'subscription_id',
        'pac_account_id',
        'rfc',
        'razon_social',
        'regimen_fiscal',
        'postal_code',
        'email',
        // LEGACY — keep for now; removed in a later migration after every
        // active profile has pac_account_id populated.
        'password',
        'sw_user_id',
        'sw_account_email',
        'certificate_number',
        'valid_from',
        'valid_to',
        'cer_file_path',
        'key_file_path',
        'is_active',
        'manifest_signed_at',
        'manifest_pdf_path',
        'manifest_sent_to_email',
        'manifest_last_attempt_error',
        'manifest_text_b64',
        'manifest_text_shown_at',
        'manifest_text_accepted_at',
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
            'is_active'             => 'boolean',
            'password'              => 'encrypted',
            'manifest_signed_at'    => 'datetime',
            'manifest_text_shown_at'    => 'datetime',
            'manifest_text_accepted_at' => 'datetime',
        ];
    }

    protected $appends = ['logo_url', 'requires_manifest'];

    /*
    |--------------------------------------------------------------------------
    | Media Library
    |--------------------------------------------------------------------------
    */

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('company_logo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Convenience accessor for the company logo URL.
     */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('company_logo') ?: null;
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

    /**
     * The PAC account that hosts this RFC.
     *
     * A single account may host multiple fiscal profiles (RFCs).
     */
    public function pacAccount(): BelongsTo
    {
        return $this->belongsTo(PacAccount::class);
    }

    /**
     * Stamp purchase history for this fiscal profile.
     */
    public function stampPurchases(): HasMany
    {
        return $this->hasMany(StampPurchase::class);
    }

    /**     * Stamp movement ledger for this fiscal profile.
     */
    public function stampMovements(): HasMany
    {
        return $this->hasMany(StampMovement::class);
    }

    /**     * Invoices issued under this fiscal profile.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Stamp reservations for this fiscal profile.
     */
    public function stampReservations(): HasMany
    {
        return $this->hasMany(StampReservation::class);
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

    /**
     * Scope to fiscal profiles usable as CFDI emitters: active AND linked
     * to an active PAC account (or legacy sw_user_id before backfill).
     */
    public function scopeReadyForInvoicing(Builder $query): Builder
    {
        return $query->active()->where(function (Builder $q) {
            $q->whereHas('pacAccount', function (Builder $pq) {
                $pq->where('status', \App\Enums\PacAccountStatus::ACTIVE);
            })->orWhereNotNull('sw_user_id');
        });
    }

    /**
     * Scope to fiscal profiles hosted by a subaccount-type PAC account
     * (dealer accounts with their own per-user stamp balance).
     */
    public function scopeOnSubaccount(Builder $query): Builder
    {
        return $query->whereHas('pacAccount', function (Builder $pq) {
            $pq->where('account_type', \App\Enums\PacAccountType::SUBACCOUNT);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this profile has been successfully linked to a PAC account.
     *
     * Prefers the new pac_account relation; falls back to the legacy
     * sw_user_id column so already-provisioned profiles keep working
     * before the backfill command has run.
     */
    public function hasSwSubaccount(): bool
    {
        return ! empty($this->sw_user_id);
    }

    /**
     * Whether this profile is linked to an ACTIVE PAC account.
     *
     * A linked account that is still pending activation is NOT ready
     * for invoicing.
     */
    public function hasActivePacAccount(): bool
    {
        return $this->pacAccount?->isActive() ?? false;
    }

    /**
     * Whether this profile is linked to the PAC at all — either through
     * an active PacAccount or a legacy sw_user_id (pre-backfill).
     */
    public function isLinkedToPac(): bool
    {
        return $this->hasActivePacAccount() || $this->hasSwSubaccount();
    }

    /**
     * Resolve the PAC login credentials for this profile.
     *
     * Prefers the new pac_account; falls back to the legacy
     * sw_account_email / email + password columns so already-provisioned
     * profiles keep working before the backfill has run.
     *
     * @return array{login_email: string|null, password: string|null}
     */
    public function resolvePacCredentials(): array
    {
        if ($this->pacAccount && $this->pacAccount->hasCredentials()) {
            return [
                'login_email' => $this->pacAccount->login_email,
                'password'    => $this->pacAccount->password,
            ];
        }

        return [
            'login_email' => $this->sw_account_email ?? $this->email,
            'password'    => $this->password,
        ];
    }

    /**
     * Whether this profile is ready to be used as an emitter in a CFDI.
     * Requires the profile to be active and linked to a PAC account.
     *
     * Backward compatible: before the pac_accounts backfill runs, a
     * legacy sw_user_id also counts as ready.
     */
    public function isReadyForInvoicing(): bool
    {
        return $this->is_active
            && ($this->hasActivePacAccount() || $this->hasSwSubaccount());
    }

    /**
     * Whether the SW manifest has been signed for this fiscal profile.
     */
    public function hasSignedManifest(): bool
    {
        return ! empty($this->manifest_signed_at);
    }

    /**
     * Whether this profile must sign the SAT/SW manifest before stamping.
     *
     * Only dealer subaccounts require the manifest. Shared accounts (the
     * platform's own subscription account) host RFCs locally and do not need it.
     */
    public function requiresManifest(): bool
    {
        return ! ($this->pacAccount && $this->pacAccount->isShared());
    }

    /**
     * Serialized flag for the frontend (Show, Index, InvoiceForm).
     */
    public function getRequiresManifestAttribute(): bool
    {
        return $this->requiresManifest();
    }

    /**
     * Live stamp balance for this profile, branched by account type.
     *
     * - Subcuenta → saldo real del PAC (getStampsBalance).
     * - Compartida → wallet local (Disponibles/Asignados/Usados); el saldo
     *   real del PAC nunca se expone al suscriptor.
     *
     * @return array{0: array|null, 1: string|null} [balance, error]
     */
    public function stampBalance(SWUserService $swUserService): array
    {
        $pacAccount = $this->pacAccount;

        if (! $pacAccount || ! $pacAccount->isActive()) {
            return [null, null];
        }

        try {
            if ($pacAccount->isSubaccount()) {
                return [$swUserService->getStampsBalance($pacAccount->sw_user_id), null];
            }

            if ($pacAccount->isShared()) {
                return [$this->localWalletBalance(), null];
            }

            return [null, null];
        } catch (\Exception $e) {
            return [null, 'No se pudo consultar el saldo en este momento.'];
        }
    }

    /**
     * Balance object for a shared account built from the local wallet.
     * Never exposes the real PAC balance to the subscriber.
     *
     * Disponibles = saldo disponible (entradas − salidas − reservas held/ambiguous);
     * Asignados   = total entradas (incluye los 5 de regalo); Usados = total salidas.
     */
    private function localWalletBalance(): array
    {
        // Asignados = total entradas confirmadas (excluye compras pendientes de
        // transferencia hasta que el admin las apruebe).
        $assigned = (int) $this->stampMovements()
            ->walletConfirmed()
            ->where('type', 'entry')
            ->sum('quantity');

        $used = (int) $this->stampMovements()
            ->where('type', 'exit')
            ->sum('quantity');

        return [
            'stampsBalance'  => app(WalletService::class)->availableBalance($this->id),
            'stampsAssigned' => $assigned,
            'stampsUsed'     => $used,
            'isUnlimited'    => false,
            'local'          => true,
        ];
    }

    /**
     * Whether the manifest text was accepted recently (within 24 hours)
     * and can be retried without re-showing the text.
     */
    public function canRetryManifestSigning(): bool
    {
        if (! $this->manifest_text_accepted_at) {
            return false;
        }

        return $this->manifest_text_accepted_at->diffInHours(now()) < 24;
    }
}
