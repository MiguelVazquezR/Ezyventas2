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
        'series',
        'folio',
        'status',
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
        'payment_form',
        'payment_method',
        'currency',
        'subtotal',
        'discount_total',
        'taxes_total',
        'total',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status'         => InvoiceStatus::class,
            'issued_at'      => 'datetime',
            'canceled_at'    => 'datetime',
            'subtotal'       => 'decimal:2',
            'discount_total' => 'decimal:2',
            'taxes_total'    => 'decimal:2',
            'total'          => 'decimal:2',
        ];
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

    public function items(): HasMany
    {
        return $this->hasMany(\App\Models\InvoiceItem::class);
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
