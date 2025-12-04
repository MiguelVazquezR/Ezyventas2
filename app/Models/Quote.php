<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Quote extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'folio',
        'branch_id',
        'user_id',
        'customer_id',
        'transaction_id',
        'parent_quote_id',
        'expiry_date',
        'status',
        'subtotal',
        'total_discount',
        'total_tax',
        'tax_type',
        'tax_rate',
        'shipping_cost',
        'total_amount',
        'notes',
        'version_number',
        'custom_fields',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'shipping_address',
        'status_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'expiry_date' => 'date',
            'status_changed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'total_discount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'custom_fields' => 'array',
            'shipping_address' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // CORRECCIÓN: Ampliamos los campos vigilados para un historial detallado
            ->logOnly([
                'folio',
                'status',
                'expiry_date',
                'subtotal',
                'total_discount',
                'total_tax',
                'shipping_cost',
                'total_amount',
                'notes',
                'recipient_name',
                'recipient_email',
                'recipient_phone',
                'shipping_address',
                'custom_fields'
            ])
            ->setDescriptionForEvent(fn(string $eventName) => "La cotización ha sido {$this->translateEventName($eventName)}")
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    private function translateEventName(string $eventName): string
    {
        return ['created' => 'creada', 'updated' => 'actualizada', 'deleted' => 'eliminada'][$eventName] ?? $eventName;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Obtiene la cotización anterior (si es una nueva versión).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'parent_quote_id');
    }

    /**
     * Obtiene las nuevas versiones de esta cotización.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Quote::class, 'parent_quote_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }
}