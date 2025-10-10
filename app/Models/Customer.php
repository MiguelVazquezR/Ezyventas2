<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'company_name',
        'name',
        'email',
        'phone',
        'address',
        'tax_id',
        'balance',
        'credit_limit',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
        ];
    }

    protected $appends = ['available_credit'];
    
    // --- ACCESORS ---

    /**
     * Calcula el crédito disponible del cliente.
     * Si el balance es negativo (deuda), se resta del límite de crédito.
     */
    protected function availableCredit(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->balance < 0
                ? $this->credit_limit + $this->balance // balance es negativo, así que se suma
                : $this->credit_limit,
        );
    }

    /**
     * Genera un historial unificado de movimientos y transacciones del cliente.
     */
    protected function historicalMovements(): Attribute
    {
        return Attribute::make(
            get: function () {
                $balanceMovements = $this->balanceMovements()->with('transaction:id,folio')->get();
                $transactions = $this->transactions()->get();

                // 1. Mapear los movimientos de saldo a un formato estándar
                $formattedMovements = $balanceMovements->map(function ($movement) {
                    return (object) [
                        'date' => $movement->created_at,
                        'type' => str_replace('_', ' ', $movement->type->value),
                        'description' => $movement->notes ?? 'Abono a venta #' . $movement->transaction?->folio,
                        'amount' => $movement->amount,
                        'resulting_balance' => $movement->balance_after,
                        'transaction_id' => $movement->transaction_id,
                    ];
                });

                // 2. Identificar transacciones que no generaron movimiento de saldo (ej. ventas de contado)
                $movementTransactionIds = $balanceMovements->pluck('transaction_id')->filter()->unique();
                $transactionsWithoutMovement = $transactions->whereNotIn('id', $movementTransactionIds);

                // 3. Mapear estas transacciones y encontrar su saldo resultante
                $formattedTransactions = $transactionsWithoutMovement->map(function ($transaction) use ($formattedMovements) {
                    // Buscar el movimiento inmediatamente anterior para saber cuál era el saldo
                    $previousMovement = $formattedMovements
                        ->where('date', '<=', $transaction->created_at)
                        ->sortByDesc('date')
                        ->first();
                    
                    $balance = $previousMovement ? $previousMovement->resulting_balance : 0;

                    return (object) [
                        'date' => $transaction->created_at,
                        'type' => 'Venta',
                        'description' => 'Venta de contado #' . $transaction->folio,
                        'amount' => $transaction->total,
                        'resulting_balance' => $balance,
                        'transaction_id' => $transaction->id,
                    ];
                });

                // 4. Unir y ordenar todas las entradas
                return $formattedMovements->concat($formattedTransactions)
                    ->sortByDesc('date')
                    ->values(); // Reset array keys
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    /**
     * Obtiene la sucursal "hogar" del cliente.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Obtiene todas las transacciones de este cliente.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Obtiene el historial de movimientos de saldo para este cliente.
     */
    public function balanceMovements(): HasMany
    {
        return $this->hasMany(CustomerBalanceMovement::class);
    }
}