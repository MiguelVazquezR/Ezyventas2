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
        'folio',
        'customer_id',
        'contact_info', // Nuevo: Datos temporales (Guest)
        'branch_id',
        'user_id',
        'cash_register_session_id',
        'transactionable_id',
        'transactionable_type',
        'status',
        'delivery_status', // Nuevo: Estatus logístico
        'channel',
        'subtotal',
        'shipping_cost', // Nuevo: Costo de envío
        'total_discount',
        'total_tax',
        'currency',
        'notes',
        'shipping_address', // Nuevo: Dirección
        'status_changed_at',
        'invoiced',
        'layaway_expiration_date',
        'delivery_date', // Nuevo: Fecha pactada
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'channel' => TransactionChannel::class,
            'subtotal' => 'decimal:2',
            'shipping_cost' => 'decimal:2', // Nuevo
            'total_discount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'status_changed_at' => 'datetime',
            'invoiced' => 'boolean',
            'layaway_expiration_date' => 'date',
            'delivery_date' => 'datetime', // Nuevo
            'contact_info' => 'array', // Nuevo: Para acceder como $txn->contact_info['name']
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
    | ACCESORES Y MUTADORES
    |--------------------------------------------------------------------------
    */
    protected $appends = ['total'];

    /**
     * Calcula el total de la transacción dinámicamente.
     * AHORA INCLUYE EL COSTO DE ENVÍO.
     */
    protected function total(): Attribute
    {
        return Attribute::make(
            get: fn() => ($this->subtotal - $this->total_discount) + $this->total_tax + ($this->shipping_cost ?? 0),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    public function transactionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Obtiene el cliente asociado con la transacción.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Obtiene la sucursal donde se realizó la transacción.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Obtiene el usuario (empleado) que registró la transacción.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Obtiene la sesión de caja asociada (si aplica).
     */
    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    /**
     * Obtiene los items (productos/servicios) de la transacción.
     */
    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /**
     * Obtiene los pagos realizados para esta transacción.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Las promociones aplicadas a la transacción.
     */
    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(Promotion::class, 'promotion_transaction')
            ->withPivot('discount_applied')
            ->withTimestamps();
    }

    /**
     * Obtiene los movimientos de saldo del cliente asociados a esta transacción.
     */
    public function customerBalanceMovements(): HasMany
    {
        return $this->hasMany(CustomerBalanceMovement::class);
    }
}