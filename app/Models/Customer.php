<?php

namespace App\Models;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\TransactionStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'company_name', 'name', 'email', 'phone',
        'address', 'tax_id', 'balance', 'credit_limit', 'created_at', 'updated_at',
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
    
    protected function availableCredit(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->balance < 0 ? $this->credit_limit + $this->balance : $this->credit_limit,
        );
    }

    protected function historicalMovements(): Attribute
    {
        return Attribute::make(
            get: function () {
                $balanceMovements = $this->balanceMovements()->with('transaction:id,folio')->get();
                $transactions = $this->transactions()->get();

                $formattedMovements = $balanceMovements->map(function ($movement) {
                    return (object) [
                        'date' => $movement->created_at,
                        'type' => str_replace('_', ' ', $movement->type->value ?? $movement->type),
                        'description' => $movement->notes ?? 'Abono a venta #' . $movement->transaction?->folio,
                        'amount' => $movement->amount,
                        'resulting_balance' => $movement->balance_after,
                        'transaction_id' => $movement->transaction_id,
                    ];
                });

                $movementTransactionIds = $balanceMovements->pluck('transaction_id')->filter()->unique();
                $transactionsWithoutMovement = $transactions->whereNotIn('id', $movementTransactionIds)->where('status', 'completado');

                $formattedTransactions = $transactionsWithoutMovement->map(function ($transaction) use ($formattedMovements) {
                    $previousMovement = $formattedMovements->where('date', '<=', $transaction->created_at)->sortByDesc('date')->first();
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

                return $formattedMovements->concat($formattedTransactions)->sortByDesc('date')->values();
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LÓGICA DE NEGOCIO: MOVIMIENTOS DE SALDO (REFACTOR)
    |--------------------------------------------------------------------------
    */

    /**
     * Motor base que actualiza el balance y crea el historial automáticamente.
     */
    public function applyBalanceMovement(float $amount, CustomerBalanceMovementType $type, int $transactionId, string $notes, ?Carbon $timestamp = null): CustomerBalanceMovement
    {
        $this->increment('balance', $amount);
        
        return $this->balanceMovements()->create([
            'transaction_id' => $transactionId,
            'type' => $type,
            'amount' => $amount, 
            'balance_after' => $this->balance,
            'notes' => $notes,
            'created_at' => $timestamp ?? now(),
            'updated_at' => $timestamp ?? now(),
        ]);
    }

    public function useBalance(float $amount, int $transactionId, string $notes, ?Carbon $timestamp = null): CustomerBalanceMovement
    {
        // Decrementamos el saldo (enviamos cantidad negativa)
        return $this->applyBalanceMovement(-$amount, CustomerBalanceMovementType::CREDIT_USAGE, $transactionId, $notes, $timestamp);
    }

    public function addDebt(float $amount, CustomerBalanceMovementType $debtType, int $transactionId, string $notes, ?Carbon $timestamp = null): CustomerBalanceMovement
    {
        // La deuda hace el balance más negativo
        return $this->applyBalanceMovement(-$amount, $debtType, $transactionId, $notes, $timestamp);
    }

    public function payDebt(float $amount, int $transactionId, string $notes, ?Carbon $timestamp = null): CustomerBalanceMovement
    {
        // Un abono suma al balance positivamente
        return $this->applyBalanceMovement($amount, CustomerBalanceMovementType::PAYMENT, $transactionId, $notes, $timestamp);
    }

    public function addRefund(float $amount, int $transactionId, string $notes, ?Carbon $timestamp = null): CustomerBalanceMovement
    {
        return $this->applyBalanceMovement($amount, CustomerBalanceMovementType::REFUND_CREDIT, $transactionId, $notes, $timestamp);
    }

    public function cancelDebt(float $amount, int $transactionId, string $notes, ?Carbon $timestamp = null): CustomerBalanceMovement
    {
        // Cancelar una deuda es como un abono que te devuelve crédito a favor
        return $this->applyBalanceMovement($amount, CustomerBalanceMovementType::CANCELLATION_CREDIT, $transactionId, $notes, $timestamp);
    }

     /**
     * Ajusta el saldo manualmente sin requerir una transacción (Para Store y AdjustBalance)
     */
    public function manualBalanceAdjustment(string $adjustmentType, float $amount, string $notes): void
    {
        $adjustmentAmount = $adjustmentType === 'add' ? $amount : ($amount - $this->balance);

        if ($adjustmentAmount != 0) {
            $this->increment('balance', $adjustmentAmount);

            $this->balanceMovements()->create([
                'type' => CustomerBalanceMovementType::MANUAL_ADJUSTMENT,
                'amount' => $adjustmentAmount, 
                'balance_after' => $this->balance,
                'notes' => $notes,
            ]);
        }
    }

    /* RELACIONES */
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }
    public function balanceMovements(): HasMany { return $this->hasMany(CustomerBalanceMovement::class); }
    public function layawayTransactions(): HasMany { return $this->hasMany(Transaction::class)->where('status', TransactionStatus::ON_LAYAWAY); }
    public function layawayItems(): HasManyThrough { return $this->hasManyThrough(TransactionItem::class, Transaction::class)->where('transactions.status', TransactionStatus::ON_LAYAWAY); }
}