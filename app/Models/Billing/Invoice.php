<?php

namespace App\Models\Billing;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Invoice extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'invoices';

    protected $fillable = [
        'branch_id',
        'fiscal_profile_id',
        'customer_id',
        'transaction_id',
        'prices_include_iva',
        'series',
        'folio',
        'status',
        'requires_manual_review',
        'uuid',
        'xml_url',
        'pdf_url',
        'issued_at',
        'canceled_at',
        'receiver_rfc',
        'receiver_legal_name',
        'receiver_tax_regime',
        'receiver_postal_code',
        'cfdi_use',
        'exportacion',
        'tipo_comprobante',
        'payment_form',
        'payment_method',
        'currency',
        // CFDI de Pago (Tipo P) — Complemento de Pago 2.0
        'pago_fecha',
        'pago_forma',
        'pago_moneda',
        'pago_monto',
        'pago_tipo_cambio',
        'pago_documentos',
        // Nota de crédito (Tipo E) — CFDI relacionados
        'tipo_relacion',
        'cfdi_relacionados',
        'subtotal',
        'discount_total',
        'taxes_total',
        'retained_taxes_total',
        'total',
        'exchange_rate',
        'cancellation_reason',
        'fecha_timbrado',
        'sello_cfdi',
        'sello_sat',
        'no_certificado_sat',
        'rfc_prov_certif',
        'cadena_original_sat',
        'qr_code_base64',
        'cancelation_requires_acceptance',
        'cancelation_status',
        'cancelation_requested_at',
        'cancelation_last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'status'               => InvoiceStatus::class,
            'requires_manual_review' => 'boolean',
            'transaction_id'       => 'integer',
            'prices_include_iva'   => 'boolean',
            'issued_at'            => 'datetime',
            'fecha_timbrado'       => 'datetime',
            'pago_fecha'           => 'datetime',
            'pago_documentos'      => 'array',
            'cfdi_relacionados'    => 'array',
            'pago_tipo_cambio'     => 'decimal:6',
            'canceled_at'          => 'datetime',
            'cancelation_requested_at'     => 'datetime',
            'cancelation_last_checked_at'  => 'datetime',
            'cancelation_requires_acceptance' => 'boolean',
            'created_at'           => 'datetime',
            'subtotal'             => 'decimal:2',
            'discount_total'       => 'decimal:2',
            'taxes_total'          => 'decimal:2',
            'retained_taxes_total' => 'decimal:2',
            'total'                => 'decimal:2',
            'exchange_rate'        => 'decimal:6',
        ];
    }

    /**
     * SAT-compliant ISO 8601 timestamp (Y-m-d\TH:i:s).
     *
     * Uses the real issue date (issued_at) when available, falling back to the
     * creation date (created_at) for drafts that haven't been stamped yet.
     */
    public function getFechaAttribute(): string
    {
        return ($this->issued_at ?? $this->created_at)->format('Y-m-d\TH:i:s');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'uuid'])
            ->setDescriptionForEvent(fn (string $eventName) => "La factura ha sido {$this->translateEventName($eventName)}")
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    private function translateEventName(string $eventName): string
    {
        return [
            'created' => 'creada',
            'updated' => 'actualizada',
            'deleted' => 'eliminada',
        ][$eventName] ?? $eventName;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function branch(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Branch::class);
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    /**
     * The POS sale (transaction) this invoice was generated from (1:1).
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Transaction::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(\App\Models\InvoiceItem::class);
    }

    /**
     * Stamp reservations referencing this invoice.
     */
    public function stampReservations(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(StampReservation::class, 'reference');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::PENDING);
    }

    public function scopeCertified(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::CERTIFIED);
    }

    public function scopeCanceled(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::CANCELED);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::DRAFT);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForFiscalProfile(Builder $query, int $fiscalProfileId): Builder
    {
        return $query->where('fiscal_profile_id', $fiscalProfileId);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the invoice has been successfully stamped by the SAT.
     */
    public function isCertified(): bool
    {
        return $this->status === InvoiceStatus::CERTIFIED && ! empty($this->uuid);
    }

    /**
     * Whether the invoice is still editable (draft only).
     */
    public function isEditable(): bool
    {
        return $this->status === InvoiceStatus::DRAFT;
    }
}
