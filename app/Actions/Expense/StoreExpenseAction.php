<?php

namespace App\Actions\Expense;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\BankAccount;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreExpenseAction
{
    public function execute(array $data, User $user): Expense
    {
        return DB::transaction(function () use ($data, $user) {
            $takeFromCashRegister = $data['take_from_cash_register'] ?? false;

            $expense = Expense::create(array_merge($data, [
                'user_id' => $user->id,
                'branch_id' => $user->branch_id,
            ]));

            // 1. Descontar del banco si aplica
            if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                BankAccount::find($expense->bank_account_id)?->withdraw($expense->amount);
            }

            // 2. Registrar salida de caja si aplica
            if ($expense->payment_method === PaymentMethod::CASH && $expense->status === ExpenseStatus::PAID && $takeFromCashRegister) {
                $activeSession = CashRegisterSession::where('status', CashRegisterSessionStatus::OPEN)
                    ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $user->branch_id))
                    ->latest('opened_at')
                    ->first();

                if (!$activeSession) {
                    throw ValidationException::withMessages([
                        'take_from_cash_register' => 'No se encontró una sesión de caja activa para registrar la salida de efectivo.',
                    ]);
                }

                // REFACTOR: Usando el helper del modelo CashRegisterSession
                $movement = $activeSession->registerOutflow(
                    $expense->amount, 
                    "Gasto: " . ($expense->folio ?: $expense->description), 
                    $user->id
                );

                $expense->update(['session_cash_movement_id' => $movement->id]);
            }

            return $expense;
        });
    }
}