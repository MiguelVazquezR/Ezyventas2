<?php

namespace App\Models;

use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'folio',
        'customer_id',
        'branch_id',
        'user_id',
        'cash_register_session_id',
        'status',
        'channel',
        'subtotal',
        'total_discount',
        'total_tax',
        'currency',
        'notes',
        'status_changed_at',
        'invoiced',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'channel' => TransactionChannel::class,
            'subtotal' => 'decimal:2',
            'total_discount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'status_changed_at' => 'datetime',
            'invoiced' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

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
}