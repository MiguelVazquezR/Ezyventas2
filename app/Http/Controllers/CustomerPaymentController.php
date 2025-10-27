<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Exception;

class CustomerPaymentController extends Controller
{
    /**
     * Almacena uno o más pagos (abonos) de un cliente y los aplica a sus deudas pendientes.
     */
    public function store(Request $request, Customer $customer, PaymentService $paymentService)
    {
        $validated = $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'payments.*.bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string|max:255',
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id,status,abierta',
        ]);

        try {
            DB::transaction(function () use ($customer, $validated, $paymentService) {

                $user = Auth::user();
                $sessionId = $validated['cash_register_session_id'];

                $pendingTransactions = $customer->transactions()
                    ->where('status', TransactionStatus::PENDING)
                    ->orderBy('created_at', 'asc')
                    ->get();

                foreach ($validated['payments'] as $paymentData) {
                    $amountToApply = (float) $paymentData['amount'];

                    foreach ($pendingTransactions as $transaction) {
                        if ($amountToApply <= 0.001) break;

                        $totalPaidOnTransaction = $transaction->payments()->sum('amount');
                        $pendingAmountOnTransaction = $transaction->total - $totalPaidOnTransaction;

                        if ($pendingAmountOnTransaction <= 0.001) continue;

                        $amountForThisTransaction = min($amountToApply, $pendingAmountOnTransaction);

                        $paymentService->processPayments(
                            $transaction,
                            [[
                                'amount' => $amountForThisTransaction,
                                'method' => $paymentData['method'],
                                'notes' => 'Abono a deuda. ' . ($validated['notes'] ?? ''),
                                'bank_account_id' => $paymentData['bank_account_id'] ?? null,
                            ]],
                            $sessionId
                        );

                        $customer->increment('balance', $amountForThisTransaction);

                        $customer->balanceMovements()->create([
                            'transaction_id' => $transaction->id,
                            'type' => CustomerBalanceMovementType::PAYMENT,
                            'amount' => $amountForThisTransaction,
                            'balance_after' => $customer->balance,
                            'notes' => "Abono a la venta #{$transaction->folio} (" . $paymentData['method'] . "). " . ($validated['notes'] ?? ''),
                        ]);

                        $amountToApply -= $amountForThisTransaction;
                    }

                    if ($amountToApply > 0.001) {
                        // Se crea una transacción especial para registrar este ingreso
                        $balanceTransaction = $customer->transactions()->create([
                            // --- CORRECCIÓN: Se llama al nuevo método para generar el folio ---
                            'folio' => $this->generateBalancePaymentFolio(),
                            'branch_id' => $user->branch_id,
                            'user_id' => $user->id,
                            'cash_register_session_id' => $sessionId,
                            'subtotal' => $amountToApply,
                            'total_discount' => 0,
                            'total_tax' => 0,
                            'total' => $amountToApply,
                            'channel' => TransactionChannel::BALANCE_PAYMENT,
                            'status' => TransactionStatus::COMPLETED,
                            'notes' => 'Transacción generada para registrar abono a saldo a favor.',
                        ]);

                        $paymentService->processPayments(
                            $balanceTransaction,
                            [[
                                'amount' => $amountToApply,
                                'method' => $paymentData['method'],
                                'notes' => 'Abono directo a saldo. ' . ($validated['notes'] ?? ''),
                                'bank_account_id' => $paymentData['bank_account_id'] ?? null,
                            ]],
                            $sessionId
                        );

                        $customer->increment('balance', $amountToApply);
                        $customer->balanceMovements()->create([
                            'transaction_id' => $balanceTransaction->id,
                            'type' => CustomerBalanceMovementType::PAYMENT,
                            'amount' => $amountToApply,
                            'balance_after' => $customer->balance,
                            'notes' => 'Abono a saldo a favor. ' . ($validated['notes'] ?? ''),
                        ]);
                    }
                }
            });
        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar el abono: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Abono registrado correctamente.');
    }

    /**
     * Genera un folio consecutivo para las transacciones de abono.
     *
     * @return string
     */
    private function generateBalancePaymentFolio(): string
    {
        $branchId = Auth::user()->branch_id;

        // Busca la última transacción con un folio 'ABONO-' para esta suscripción
        $lastTransaction = Transaction::where('branch_id', $branchId)
            ->where('folio', 'like', 'ABONO-%')
            ->orderByRaw('CAST(SUBSTRING(folio, 7) AS UNSIGNED) DESC') // 'ABONO-' tiene 6 caracteres
            ->first();

        $sequence = 1;
        if ($lastTransaction) {
            // Extrae la parte numérica del folio (ej. de 'ABONO-001' extrae '001' y lo convierte a 1)
            $lastNumber = (int) substr($lastTransaction->folio, 6);
            $sequence = $lastNumber + 1;
        }

        // Formatea el nuevo número con ceros a la izquierda y lo retorna con el prefijo
        return 'ABONO-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}