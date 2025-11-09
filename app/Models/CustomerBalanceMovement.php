<?php

namespace App\Models;

use App\Enums\CustomerBalanceMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBalanceMovement extends Model
{
    use HasFactory;

    protected $table = 'customer_balance_movements';

    protected $fillable = [
        'customer_id',
        'transaction_id',
        'type',
        'amount',
        'balance_after',
        'notes',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomerBalanceMovementType::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    /**
     * Obtiene el cliente al que pertenece el movimiento.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Obtiene la transacción que originó el movimiento (si aplica).
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}