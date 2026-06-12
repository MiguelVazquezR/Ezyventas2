<?php

namespace App\Models;

use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Transaction extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'folio', 'customer_id', 'contact_info', 'branch_id', 'user_id',
        'cash_register_session_id', 'transactionable_id', 'transactionable_type',
        'status', 'delivery_status', 'channel', 'subtotal', 'shipping_cost', 
        'total_discount', 'total_tax', 'currency', 'notes', 'shipping_address', 
        'status_changed_at', 'invoiced', 'layaway_expiration_date', 'delivery_date', 
        'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'channel' => TransactionChannel::class,
            'subtotal' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total_discount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'status_changed_at' => 'datetime',
            'invoiced' => 'boolean',
            'layaway_expiration_date' => 'date',
            'delivery_date' => 'datetime',
            'contact_info' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'delivery_status'])
            ->setDescriptionForEvent(fn(string $eventName) => "La transacción ha sido {$this->translateEventName($eventName)}")
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    private function translateEventName(string $eventName): string
    {
        return ['created' => 'creada', 'updated' => 'actualizada', 'deleted' => 'eliminada'][$eventName] ?? $eventName;
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESORES Y MUTADORES (REFACTOR)
    |--------------------------------------------------------------------------
    */
    protected $appends = ['total', 'total_paid', 'remaining_due'];

    protected function total(): Attribute
    {
        return Attribute::make(
            get: fn() => ($this->subtotal - $this->total_discount) + $this->total_tax + ($this->shipping_cost ?? 0),
        );
    }

    // NUEVO: Calcula cuánto se ha pagado en total
    protected function totalPaid(): Attribute
    {
        return Attribute::make(
            get: fn() => (float) $this->payments()->sum('amount'),
        );
    }

    // NUEVO: Calcula cuánto falta por pagar
    protected function remainingDue(): Attribute
    {
        return Attribute::make(
            get: fn() => max(0, $this->total - $this->total_paid),
        );
    }

    // NUEVO: Método helper de estado
    public function isFullyPaid(): bool
    {
        return $this->remaining_due <= 0.01;
    }

    /*
    |--------------------------------------------------------------------------
    | GENERADORES DE FOLIO (Movidos desde el Service)
    |--------------------------------------------------------------------------
    */
    public static function generateFolio(int $branchId): string
    {
        $lastTransaction = self::where('branch_id', $branchId)
            ->where('folio', 'LIKE', 'V-%')
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastTransaction ? ((int) substr($lastTransaction->folio, 2)) + 1 : 1;
        return 'V-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    public static function generateBalancePaymentFolio(int $branchId): string
    {
        $lastTransaction = self::where('branch_id', $branchId)
            ->where('folio', 'like', 'ABONO-%')
            ->orderByRaw('CAST(SUBSTRING(folio, 7) AS UNSIGNED) DESC')
            ->first();

        $sequence = $lastTransaction ? ((int) substr($lastTransaction->folio, 6)) + 1 : 1;
        return 'ABONO-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */
    public function transactionable(): MorphTo { return $this->morphTo(); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function cashRegisterSession(): BelongsTo { return $this->belongsTo(CashRegisterSession::class); }
    public function items(): HasMany { return $this->hasMany(TransactionItem::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function promotions(): BelongsToMany {
        return $this->belongsToMany(Promotion::class, 'promotion_transaction')
            ->withPivot('discount_applied')->withTimestamps();
    }
    public function customerBalanceMovements(): HasMany { return $this->hasMany(CustomerBalanceMovement::class); }
}