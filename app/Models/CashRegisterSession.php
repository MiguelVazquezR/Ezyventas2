<?php

namespace App\Models;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SessionCashMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class CashRegisterSession extends Model
{
    use HasFactory;

    protected $table = 'cash_register_sessions';

    protected $fillable = [
        'cash_register_id',
        'user_id',
        'opened_at',
        'closed_at',
        'status',
        'opening_cash_balance',
        'opening_bank_balances',
        'closing_cash_balance',
        'calculated_cash_total',
        'cash_difference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => CashRegisterSessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash_balance' => 'decimal:2',
            'opening_bank_balances' => 'array',
            'closing_cash_balance' => 'decimal:2',
            'calculated_cash_total' => 'decimal:2',
            'cash_difference' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | LÓGICA DE NEGOCIO (REFACTOR)
    |--------------------------------------------------------------------------
    */

    /**
     * Registra una salida de efectivo de esta sesión de caja.
     */
    public function registerOutflow(float $amount, string $description, int $userId): SessionCashMovement
    {
        return $this->cashMovements()->create([
            'type' => SessionCashMovementType::OUTFLOW,
            'amount' => $amount,
            'description' => $description,
            'user_id' => $userId,
        ]);
    }

    /**
     * Obtiene los totales de ingresos agrupados por método de pago.
     */
    public function getCompletedPaymentTotals()
    {
        return $this->payments()
            ->where('status', PaymentStatus::COMPLETED)
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');
    }

    /**
     * Calcula los saldos iniciales y finales de las cuentas bancarias para la sesión actual.
     */
    public function calculateBankAccountSummary(User $user, bool $isOwner): array
    {
        $summary = [];
        if (empty($this->opening_bank_balances)) {
            return $summary;
        }

        $openingBalances = collect($this->opening_bank_balances);
        $accountIdsInSession = $openingBalances->pluck('id');

        // 1. Obtener INGRESOS a cuentas bancarias durante esta sesión
        $paymentsToAccounts = $this->payments()
            ->whereIn('payment_method', [PaymentMethod::CARD->value, PaymentMethod::TRANSFER->value])
            ->where('status', PaymentStatus::COMPLETED->value)
            ->whereIn('bank_account_id', $accountIdsInSession)
            ->select('bank_account_id', DB::raw('SUM(amount) as total_received'))
            ->groupBy('bank_account_id')
            ->get()
            ->keyBy('bank_account_id');

        // 2. Obtener GASTOS desde cuentas bancarias durante esta sesión
        $expensesFromAccounts = Expense::where('status', ExpenseStatus::PAID->value)
            ->whereIn('payment_method', [PaymentMethod::CARD->value, PaymentMethod::TRANSFER->value])
            ->whereIn('bank_account_id', $accountIdsInSession)
            // Se usa fecha actual si la sesión aún no se cierra
            ->whereBetween('expense_date', [$this->opened_at?->toDateString(), $this->closed_at?->toDateString() ?? now()->toDateString()])
            ->select('bank_account_id', DB::raw('SUM(amount) as total_spent'))
            ->groupBy('bank_account_id')
            ->get()
            ->keyBy('bank_account_id');

        // 3. Filtrar por permisos del usuario que está viendo el reporte
        $allowedAccountIds = $isOwner ? $accountIdsInSession : $user->bankAccounts()->pluck('id');

        // 4. Calcular el resumen final
        foreach ($openingBalances as $openingData) {
            if ($allowedAccountIds->contains($openingData['id'])) {
                $received = $paymentsToAccounts->get($openingData['id'])?->total_received ?? 0;
                $spent = $expensesFromAccounts->get($openingData['id'])?->total_spent ?? 0;
                $initialBalance = (float) $openingData['balance'];
                
                $finalBalance = $initialBalance + $received - $spent;

                $summary[] = [
                    'id' => $openingData['id'],
                    'account_name' => $openingData['account_name'],
                    'bank_name' => $openingData['bank_name'],
                    'initial_balance' => $initialBalance,
                    'final_balance' => $finalBalance,
                ];
            }
        }
        return $summary;
    }

    /**
     * Realiza el proceso de corte de caja calculando los totales de flujo de efectivo.
     */
    public function closeSession(float $closingCashBalance, ?string $notes = null): void
    {
        $cashSales = $this->payments()
            ->where('payment_method', PaymentMethod::CASH)
            ->where('status', PaymentStatus::COMPLETED)
            ->sum('amount');

        $inflows = $this->cashMovements()->where('type', 'ingreso')->sum('amount');
        $outflows = $this->cashMovements()->where('type', 'egreso')->sum('amount');

        $calculatedTotal = $this->opening_cash_balance + $cashSales + $inflows - $outflows;
        $difference = $closingCashBalance - $calculatedTotal;

        $this->update([
            'closing_cash_balance' => $closingCashBalance,
            'calculated_cash_total' => $calculatedTotal,
            'cash_difference' => $difference,
            'notes' => $notes,
            'status' => CashRegisterSessionStatus::CLOSED,
            'closed_at' => now(),
        ]);

        $this->cashRegister->update(['in_use' => false]);
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cash_register_session_user');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(SessionCashMovement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}