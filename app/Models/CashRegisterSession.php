<?php

namespace App\Models;

use App\Traits\HasSubscription;
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
    use HasFactory, HasSubscription;

    protected $table = 'cash_register_sessions';

    protected $fillable = [
        'cash_register_id',
        'user_id',
        'opened_at',
        'closed_at',
        'status',
        'opening_cash_balance',
        'opening_bank_balances',
        'closing_bank_balances',
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
            'closing_bank_balances' => 'array',
            'closing_cash_balance' => 'decimal:2',
            'calculated_cash_total' => 'decimal:2',
            'cash_difference' => 'decimal:2',
        ];
    }

    /**
     * Returns the subscription ID via cashRegister -> branch -> subscription.
     */
    public function getSubscriptionId(): ?int
    {
        $cashRegister = $this->relationLoaded('cashRegister')
            ? $this->cashRegister
            : $this->cashRegister()->first();

        if (!$cashRegister) {
            return null;
        }

        $branch = $cashRegister->relationLoaded('branch')
            ? $cashRegister->branch
            : $cashRegister->branch()->first();

        return $branch?->subscription_id;
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
     * Calcula los saldos iniciales y finales de las cuentas bancarias para la sesión actual
     * SIN filtrar por permisos del usuario. Es la fuente de verdad para la conciliación
     * al cierre de caja (write-back a BankAccount.balance).
     */
    public function computeBankAccountBalances(): array
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

        // 2. Obtener GASTOS desde cuentas bancarias durante esta sesión.
        //    Se atribuyen por created_at (timestamp) y no por expense_date (DATE),
        //    porque el balance se descuenta cuando se registra el gasto, no en su
        //    fecha contable. Así no se contaminan sesiones con gastos del mismo día
        //    creados antes de abrir o después de cerrar.
        $expensesFromAccounts = Expense::where('status', ExpenseStatus::PAID->value)
            ->whereIn('payment_method', [PaymentMethod::CARD->value, PaymentMethod::TRANSFER->value])
            ->whereIn('bank_account_id', $accountIdsInSession)
            ->whereBetween('created_at', [$this->opened_at?->toDateTimeString(), $this->closed_at?->toDateTimeString() ?? now()->toDateTimeString()])
            ->select('bank_account_id', DB::raw('SUM(amount) as total_spent'))
            ->groupBy('bank_account_id')
            ->get()
            ->keyBy('bank_account_id');

        // 2b. Obtener TRANSFERENCIAS entre cuentas durante esta sesión.
        //     Se comparan con precisión de datetime para no incluir movimientos
        //     del mismo día ocurridos antes de abrir o después de cerrar la sesión.
        $sessionStart = $this->opened_at?->toDateTimeString();
        $sessionEnd = $this->closed_at?->toDateTimeString() ?? now()->toDateTimeString();

        $transfersIn = BankAccountTransfer::whereIn('to_account_id', $accountIdsInSession)
            ->whereBetween('transfer_date', [$sessionStart, $sessionEnd])
            ->select('to_account_id', DB::raw('SUM(amount) as total_transferred_in'))
            ->groupBy('to_account_id')
            ->get()
            ->keyBy('to_account_id');

        $transfersOut = BankAccountTransfer::whereIn('from_account_id', $accountIdsInSession)
            ->whereBetween('transfer_date', [$sessionStart, $sessionEnd])
            ->select('from_account_id', DB::raw('SUM(amount) as total_transferred_out'))
            ->groupBy('from_account_id')
            ->get()
            ->keyBy('from_account_id');

        // 2c. Si la sesión ya fue cerrada, usar los saldos finales congelados en el
        //     momento del corte como fuente de verdad (histórico inmutable).
        $closingBalances = collect($this->closing_bank_balances ?? [])->keyBy('id');

        // 3. Calcular el resumen final
        foreach ($openingBalances as $openingData) {
            $received = $paymentsToAccounts->get($openingData['id'])?->total_received ?? 0;
            $spent = $expensesFromAccounts->get($openingData['id'])?->total_spent ?? 0;
            $transferredIn = $transfersIn->get($openingData['id'])?->total_transferred_in ?? 0;
            $transferredOut = $transfersOut->get($openingData['id'])?->total_transferred_out ?? 0;
            $initialBalance = (float) $openingData['balance'];

            $computedFinal = $initialBalance + $received - $spent + $transferredIn - $transferredOut;
            $closing = $closingBalances->get($openingData['id']);
            $finalBalance = (float) ($closing['balance'] ?? $computedFinal);

            $summary[] = [
                'id' => $openingData['id'],
                'account_name' => $openingData['account_name'],
                'bank_name' => $openingData['bank_name'],
                'initial_balance' => $initialBalance,
                'received' => (float) $received,
                'spent' => (float) $spent,
                'transferred_in' => (float) $transferredIn,
                'transferred_out' => (float) $transferredOut,
                'final_balance' => $finalBalance,
            ];
        }
        return $summary;
    }

    /**
     * Calcula los saldos iniciales y finales de las cuentas bancarias para la sesión actual
     * filtrando por los permisos del usuario que consulta el reporte.
     */
    public function calculateBankAccountSummary(User $user, bool $isOwner): array
    {
        $summary = $this->computeBankAccountBalances();

        if (empty($summary)) {
            return $summary;
        }

        $accountIdsInSession = collect($summary)->pluck('id');
        $allowedAccountIds = $isOwner ? $accountIdsInSession : $user->bankAccounts()->pluck('id');

        return collect($summary)
            ->filter(fn(array $row) => $allowedAccountIds->contains($row['id']))
            ->values()
            ->all();
    }

    /**
     * Realiza el proceso de corte de caja calculando los totales de flujo de efectivo.
     * Además concilia los saldos bancarios: calcula el saldo final por cuenta y lo
     * persiste en el snapshot de cierre y en BankAccount.balance, de modo que la
     * siguiente sesión arranque exactamente con el saldo con el que terminó esta.
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

        // Conciliar saldos bancarios: calcular el saldo final por cuenta,
        // persistirlo en el snapshot de cierre y escribirlo de regreso a la cuenta.
        $bankClosingBalances = [];
        foreach ($this->computeBankAccountBalances() as $bankSummary) {
            $bankClosingBalances[] = [
                'id' => $bankSummary['id'],
                'account_name' => $bankSummary['account_name'],
                'bank_name' => $bankSummary['bank_name'],
                'balance' => $bankSummary['final_balance'],
            ];

            BankAccount::find($bankSummary['id'])?->update(['balance' => $bankSummary['final_balance']]);
        }

        $this->update([
            'closing_cash_balance' => $closingCashBalance,
            'calculated_cash_total' => $calculatedTotal,
            'cash_difference' => $difference,
            'closing_bank_balances' => $bankClosingBalances,
            'notes' => $notes,
            'status' => CashRegisterSessionStatus::CLOSED,
            'closed_at' => now(),
        ]);

        $this->cashRegister->update(['in_use' => false]);
    }

    /**
     * Actualiza únicamente el monto de "contado físico" (closing_cash_balance)
     * y recalcula la diferencia de caja sin alterar el resto de campos.
     */
    public function updateClosingCashBalance(float $newClosingCashBalance): void
    {
        $difference = $newClosingCashBalance - $this->calculated_cash_total;

        $this->update([
            'closing_cash_balance' => $newClosingCashBalance,
            'cash_difference' => $difference,
        ]);
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

    /**
     * Pagos en efectivo completados: efectivo que ingresó a la caja por ventas.
     */
    public function cashPayments(): HasMany
    {
        return $this->payments()
            ->where('payment_method', PaymentMethod::CASH)
            ->where('status', PaymentStatus::COMPLETED);
    }

    /**
     * Movimientos manuales de ingreso de efectivo a la caja.
     */
    public function inflowMovements(): HasMany
    {
        return $this->cashMovements()
            ->where('type', SessionCashMovementType::INFLOW);
    }

    /**
     * Movimientos manuales de retiro de efectivo de la caja.
     */
    public function outflowMovements(): HasMany
    {
        return $this->cashMovements()
            ->where('type', SessionCashMovementType::OUTFLOW);
    }
}