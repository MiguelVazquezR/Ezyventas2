<?php

namespace App\Actions\Expense;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\BankAccount;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateExpenseAction
{
    public function execute(Expense $expense, array $data, User $user): Expense
    {
        $expense->load('sessionCashMovement');
        $originalMovement = $expense->sessionCashMovement;

        $originalAmount = $expense->amount;
        $originalStatus = $expense->status;
        $originalBankAccountId = $expense->bank_account_id;

        return DB::transaction(function () use ($expense, $data, $user, $originalMovement, $originalAmount, $originalStatus, $originalBankAccountId) {
            
            $newAmount = $data['amount'];
            $newStatus = ExpenseStatus::from($data['status']);
            $newPaymentMethod = PaymentMethod::from($data['payment_method']);
            $newBankAccountId = $data['bank_account_id'] ?? null;
            $takeFromCashRegister = $data['take_from_cash_register'] ?? false;
            $isExternal = $data['is_external'] ?? false;
            $wasExternal = $expense->is_external;

            // 1. REVERTIR SALDO BANCARIO (solo si era un gasto interno)
            if (! $wasExternal && $originalStatus === ExpenseStatus::PAID && $originalBankAccountId) {
                BankAccount::find($originalBankAccountId)?->deposit($originalAmount);
            }

            // 2. ACTUALIZAR EL GASTO (Temporalmente limpiamos el movement_id)
            $data['session_cash_movement_id'] = null;
            $expense->update($data);

            // 3. APLICAR NUEVO SALDO BANCARIO (solo gastos internos)
            if (! $isExternal && $newStatus === ExpenseStatus::PAID && $newBankAccountId) {
                BankAccount::find($newBankAccountId)?->withdraw($newAmount);
            }

            // 4. LÓGICA DE MOVIMIENTO DE CAJA (solo gastos internos)
            $isCashWithdrawal = ! $isExternal && $newPaymentMethod === PaymentMethod::CASH && $newStatus === ExpenseStatus::PAID && $takeFromCashRegister;

            if ($isCashWithdrawal) {
                if ($originalMovement) {
                    $originalMovement->update([
                        'amount' => $newAmount,
                        'description' => "Gasto (actualizado): " . ($expense->folio ?: $expense->description),
                        'user_id' => $user->id,
                    ]);
                    $expense->update(['session_cash_movement_id' => $originalMovement->id]);
                } else {
                    $expenseDate = $expense->expense_date;
                    $session = CashRegisterSession::whereHas('cashRegister', fn($q) => $q->where('branch_id', $user->branch_id))
                        ->where('opened_at', '<=', $expenseDate->endOfDay())
                        ->latest('opened_at')
                        ->first();

                    if (!$session) {
                        throw ValidationException::withMessages([
                            'take_from_cash_register' => 'No se encontró una sesión de caja para la fecha del gasto. No se puede registrar salida.',
                        ]);
                    }

                    $movement = $session->registerOutflow($newAmount, "Gasto (creado en actualización): " . ($expense->folio ?: $expense->description), $user->id);
                    $expense->update(['session_cash_movement_id' => $movement->id]);
                }
            } else {
                if ($originalMovement) {
                    $originalMovement->delete();
                }
            }

            return $expense;
        });
    }
}