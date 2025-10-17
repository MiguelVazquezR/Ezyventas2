<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankAccountTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankAccountController extends Controller
{
    /**
     * Obtiene las cuentas bancarias para el usuario actual.
     * Propietarios: Todas las de la sucursal.
     * Empleados: Solo las asignadas.
     */
    public function getForBranch()
    {
        $user = Auth::user();
        $isOwner = !$user->roles()->exists();

        if ($isOwner) {
            $bankAccounts = BankAccount::whereHas('branches', function ($query) use ($user) {
                $query->where('branch_id', $user->branch_id);
            })->get();
        } else {
            $bankAccounts = $user->bankAccounts()->get();
        }

        return response()->json($bankAccounts);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'card_number' => 'nullable|string|max:255',
            'clabe' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric|min:0',
            'branches' => 'sometimes|array', // 'sometimes' en lugar de 'required'
            'branches.*.id' => ['required', Rule::in($subscription->branches->pluck('id')->toArray())],
            'branches.*.is_favorite' => 'required|boolean',
        ], [
            'branches.required' => 'Debes asignar la cuenta al menos a una sucursal.',
        ]);

        DB::transaction(function () use ($validated, $subscription, $user) {
            $bankAccount = $subscription->bankAccounts()->create(
                collect($validated)->except(['branches'])->all()
            );

            // Si se proporcionan sucursales, se sincronizan.
            if (!empty($validated['branches'])) {
                $branchesToSync = collect($validated['branches'])->mapWithKeys(function ($branch) {
                    return [$branch['id'] => ['is_favorite' => $branch['is_favorite']]];
                });
                $bankAccount->branches()->attach($branchesToSync);
            } else {
                // Si no, se asigna a la sucursal actual del usuario.
                $bankAccount->branches()->attach($user->branch_id, ['is_favorite' => false]);
            }
        });

        return redirect()->back()->with('success', 'Cuenta bancaria creada con éxito.');
    }

    public function update(Request $request, BankAccount $bankAccount)
    {
        if ($bankAccount->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403);
        }

        $branchIds = $bankAccount->subscription->branches->pluck('id')->toArray();

        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'card_number' => 'nullable|string|max:255',
            'clabe' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric|min:0',
            'branches' => 'required|array|min:1',
            'branches.*.id' => ['required', Rule::in($branchIds)],
            'branches.*.is_favorite' => 'required|boolean',
        ], [
            'branches.required' => 'Debes asignar la cuenta al menos a una sucursal.',
        ]);

        DB::transaction(function () use ($validated, $bankAccount) {
            $bankAccount->update(
                collect($validated)->except(['branches'])->all()
            );

            $branchesToSync = collect($validated['branches'])->mapWithKeys(function ($branch) {
                return [$branch['id'] => ['is_favorite' => $branch['is_favorite']]];
            });

            $bankAccount->branches()->sync($branchesToSync);
        });

        return redirect()->back()->with('success', 'Cuenta bancaria actualizada con éxito.');
    }

    public function destroy(BankAccount $bankAccount)
    {
        if ($bankAccount->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403);
        }

        $bankAccount->delete();

        return redirect()->back()->with('success', 'Cuenta bancaria eliminada con éxito.');
    }

    /**
     * Obtiene el historial de movimientos para una cuenta bancaria.
     */
    public function getHistory(BankAccount $bankAccount)
    {
        if ($bankAccount->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403);
        }

        $inflows = $bankAccount->payments()->with('transaction:id,folio')->get()->map(function ($payment) {
            return [
                'date' => $payment->created_at,
                'type' => 'Ingreso por Venta',
                'description' => 'Pago de Venta',
                'folio' => $payment->transaction->folio ?? 'N/A',
                'method' => $payment->payment_method->value,
                'amount' => (float) $payment->amount,
                'related_url' => route('transactions.show', $payment->transaction_id),
            ];
        });

        $outflows = $bankAccount->expenses()->get()->map(function ($expense) {
            return [
                'date' => $expense->created_at,
                'type' => 'Egreso por Gasto',
                'description' => $expense->description,
                'folio' => $expense->folio ?? 'N/A',
                'method' => $expense->payment_method->value,
                'amount' => -(float) $expense->amount,
                'related_url' => route('expenses.show', $expense->id),
            ];
        });

        $transfersOut = $bankAccount->transfersFrom()->with('toAccount:id,account_name')->get()->map(function ($transfer) {
            return [
                'date' => $transfer->created_at,
                'type' => 'Transferencia Enviada',
                'description' => 'A: ' . $transfer->toAccount->account_name,
                'folio' => $transfer->folio, // Usar el folio real
                'method' => 'transferencia',
                'amount' => -(float) $transfer->amount,
                'related_url' => null,
            ];
        });

        $transfersIn = $bankAccount->transfersTo()->with('fromAccount:id,account_name')->get()->map(function ($transfer) {
            return [
                'date' => $transfer->created_at,
                'type' => 'Transferencia Recibida',
                'description' => 'De: ' . $transfer->fromAccount->account_name,
                'folio' => $transfer->folio, // Usar el folio real
                'method' => 'transferencia',
                'amount' => (float) $transfer->amount,
                'related_url' => null,
            ];
        });

        $allMovements = $inflows->concat($outflows)->concat($transfersIn)->concat($transfersOut)->sortByDesc('date');

        $runningBalance = (float) $bankAccount->balance;
        $history = $allMovements->map(function ($movement) use (&$runningBalance) {
            $movement['balance_after'] = $runningBalance;
            $runningBalance -= $movement['amount'];
            return $movement;
        })->values();

        return response()->json($history);
    }

    public function storeTransfer(Request $request)
    {
        $validated = $request->validate([
            'from_account_id' => 'required|exists:bank_accounts,id',
            'to_account_id' => 'required|exists:bank_accounts,id|different:from_account_id',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        $fromAccount = BankAccount::find($validated['from_account_id']);
        $toAccount = BankAccount::find($validated['to_account_id']);
        $subscriptionId = Auth::user()->branch->subscription_id;

        if ($fromAccount->subscription_id !== $subscriptionId || $toAccount->subscription_id !== $subscriptionId) {
            abort(403);
        }

        if ($fromAccount->balance < $validated['amount']) {
            throw ValidationException::withMessages([
                'amount' => 'Saldo insuficiente en la cuenta de origen.',
            ]);
        }

        DB::transaction(function () use ($validated, $fromAccount, $toAccount, $subscriptionId) {
            // --- Lógica de generación de folio consecutivo ---
            $lastTransfer = BankAccountTransfer::where('subscription_id', $subscriptionId)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $nextFolioNumber = $lastTransfer ? ((int) substr($lastTransfer->folio, 3)) + 1 : 1;
            $folio = 'TR-' . str_pad($nextFolioNumber, 3, '0', STR_PAD_LEFT);
            // --- Fin de la lógica ---

            $fromAccount->decrement('balance', $validated['amount']);
            $toAccount->increment('balance', $validated['amount']);

            BankAccountTransfer::create([
                'folio' => $folio,
                'subscription_id' => $subscriptionId,
                'from_account_id' => $validated['from_account_id'],
                'to_account_id' => $validated['to_account_id'],
                'amount' => $validated['amount'],
                'notes' => $validated['notes'],
                'transfer_date' => now(),
            ]);
        });

        return redirect()->back()->with('success', 'Transferencia realizada con éxito.');
    }
}
