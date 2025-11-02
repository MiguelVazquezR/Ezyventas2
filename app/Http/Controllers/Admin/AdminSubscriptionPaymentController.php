<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Enums\SubscriptionStatus;
use App\Enums\BillingPeriod;
use App\Enums\ExpenseStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Mail\PaymentStatusNotification;
use App\Models\BankAccount;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class AdminSubscriptionPaymentController extends Controller
{
    /**
     * Muestra la lista de pagos pendientes.
     */
    public function index()
    {
        $pendingPayments = SubscriptionPayment::with([
            'subscriptionVersion.subscription.branches' // Cargar info de la sucursal
        ])
            ->where('payment_method', 'transferencia')
            ->where('status', SubscriptionPaymentStatus::PENDING)
            ->latest()
            ->get();

        return Inertia::render('Admin/Payment/Index', [
            'pendingPayments' => $pendingPayments,
        ]);
    }

    /**
     * Muestra el detalle de un pago para aprobar o rechazar.
     */
    public function show(SubscriptionPayment $payment)
    {
        // Cargamos las relaciones necesarias
        $payment->load([
            'subscriptionVersion.subscription:id,commercial_name',
            'subscriptionVersion.items',
            'media' // Carga la colección de medios
        ]);

        // --- INICIO: Lógica de Comparación de Items ---
        $pendingVersion = $payment->subscriptionVersion;
        $subscription = $pendingVersion->subscription;

        // 1. Buscar la versión anterior a la que se está pagando
        $previousVersion = $subscription->versions()
            ->where('id', '!=', $pendingVersion->id) // Excluir la versión pendiente actual
            ->latest('id') // Obtener la más reciente
            ->with('items')
            ->first();

        // 2. Crear un mapa de los items anteriores para consulta rápida
        $previousItemsMap = $previousVersion ? $previousVersion->items->keyBy('item_key') : collect();

        // 3. Procesar los items de la versión pendiente para compararlos
        $processedItems = $pendingVersion->items->map(function ($newItem) use ($previousItemsMap) {
            $previousItem = $previousItemsMap->get($newItem->item_key);
            $previousQuantity = $previousItem ? $previousItem->quantity : 0;
            $newQuantity = $newItem->quantity;
            $status = 'unchanged'; // Estado por defecto

            if (!$previousItem) {
                // El item no existía en la versión anterior
                $status = 'new';
            } elseif ($newQuantity > $previousQuantity) {
                // La cantidad del item aumentó
                $status = 'upgraded';
            } elseif ($newQuantity < $previousQuantity && $previousQuantity > 0) {
                // La cantidad disminuyó (raro en este flujo, pero posible)
                $status = 'downgraded';
            }

            return [
                'name' => $newItem->name,
                'quantity' => $newQuantity,
                'billing_period' => $newItem->billing_period,
                'unit_price' => $newItem->unit_price,
                'status' => $status,
                'previous_quantity' => $previousQuantity,
            ];
        });
        // --- FIN: Lógica de Comparación de Items ---


        // --- Lógica de Comprobante (Corregida) ---
        $proofUrl = null;
        if ($payment->hasMedia('proof_of_payment')) {
            // Cambiamos getTemporaryUrl() por getUrl()
            // Esto asume que estás usando el disco 'public' y
            // que ejecutaste `php artisan storage:link`.
            $proofUrl = $payment->getFirstMedia('proof_of_payment')->getUrl();
        }

        return Inertia::render('Admin/Payment/Show', [
            'payment' => $payment,
            'proofUrl' => $proofUrl,
            'processedItems' => $processedItems, // Enviamos los items procesados a la vista
        ]);
    }

    /**
     * Aprueba un pago por transferencia.
     */
    public function approve(SubscriptionPayment $payment)
    {
        if ($payment->status !== SubscriptionPaymentStatus::PENDING || $payment->payment_method !== 'transferencia') {
            return redirect()->route('admin.payments.index')->with('error', 'Este pago no se puede aprobar.');
        }

        try {
            DB::transaction(function () use ($payment) {
                $version = $payment->subscriptionVersion;
                $subscription = $version->subscription;

                // 1. Determinar las fechas de la versión
                // Cargar la versión actual "real" (la última activa o vencida)
                $currentActiveVersion = $subscription->versions()
                    ->where('id', '!=', $version->id) // Excluir la que estamos aprobando
                    ->latest('id')
                    ->first();

                // --- INICIO: REGLA 4 - Lógica de Fechas de Aprobación MODIFICADA ---
                $startDate = now()->startOfDay(); // Default: iniciar hoy
                $mode = 'renew'; // Asumir renovación por defecto

                // Inferir el modo: si la versión anterior aún no vence, es una MEJORA.
                if ($currentActiveVersion && $currentActiveVersion->end_date->isFuture()) {
                    $mode = 'upgrade';
                }

                if ($mode === 'upgrade') {
                    // MEJORA: El nuevo plan inicia HOY.
                    $startDate = now()->startOfDay();

                    // PUNTO 2: Acortamos la versión anterior para que termine HOY.
                    if ($currentActiveVersion) {
                        $currentActiveVersion->update(['end_date' => $startDate]);
                    }
                } else {
                    // RENOVACIÓN:
                    if ($currentActiveVersion && $currentActiveVersion->end_date->isFuture()) {
                        // Si paga ANTES, la nueva versión empieza cuando la actual TERMINA
                        $startDate = $currentActiveVersion->end_date;
                    } else {
                        // Si paga DESPUÉS (o es nueva), la nueva empieza HOY
                        $startDate = now()->startOfDay();
                    }
                }
                // --- FIN: REGLA 4 ---

                // Calculamos la fecha de fin basada en el periodo
                $billingPeriod = $version->items->first()->billing_period; // Asumimos que todos los items tienen el mismo
                $endDate = $billingPeriod === \App\Enums\BillingPeriod::ANNUALLY
                    ? $startDate->copy()->addYear()
                    : $startDate->copy()->addMonth();

                // 2. Actualizar la versión que estamos aprobando
                $version->update([
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]);

                // 3. Actualizar el pago
                $payment->update([
                    'status' => SubscriptionPaymentStatus::APPROVED,
                    'payment_details' => null,
                ]);

                // 4. Actualizar el estado general de la suscripción
                $subscription->update([
                    'status' => SubscriptionStatus::ACTIVE,
                ]);

                // --- INICIO: LÓGICA DE GASTO (NUEVO) ---
                
                // 5. Buscar el gasto 'pendiente' que coincida con este pago
                // Lo buscamos por la suscripción, el monto y el estado.
                $expense = Expense::where('status', ExpenseStatus::PENDING)
                    ->where('amount', $payment->amount)
                    ->where('description', 'like', 'Pago de suscripción%')
                    ->whereHas('branch.subscription', fn($q) => $q->where('id', $subscription->id))
                    ->latest('created_at') // Tomar el más reciente que coincida
                    ->first();

                if ($expense) {
                    // 6. Actualizar el Gasto a 'pagado'
                    $expense->update(['status' => ExpenseStatus::PAID]);

                    // 7. Descontar el saldo de la cuenta bancaria del *cliente*
                    if ($expense->bank_account_id) {
                        // Usar lockForUpdate para evitar condiciones de carrera al restar saldo
                        $bankAccount = BankAccount::lockForUpdate()->find($expense->bank_account_id);
                        
                        if ($bankAccount) {
                            $bankAccount->decrement('balance', $expense->amount);
                        }
                    }
                }
                // --- FIN: LÓGICA DE GASTO ---

            });
        } catch (\Exception $e) {
            Log::error("Error al aprobar pago: " . $e->getMessage());
            return redirect()->route('admin.payments.index')->with('error', 'Error al aprobar el pago: ' . $e->getMessage());
        }

        // --- INICIO: Notificar al Suscriptor (Aprobado) ---
        try {
            // Recargamos las relaciones para obtener los datos del usuario
            $payment->load('subscriptionVersion.subscription');
            $subscription = $payment->subscriptionVersion->subscription;
            
            // Usamos el email de contacto principal de la suscripción
            $subscriptionEmail = $subscription->contact_email; 

            if ($subscriptionEmail) {
                Mail::to($subscriptionEmail)
                    ->send(new PaymentStatusNotification(
                        $payment,
                        'approved',
                        $subscription->commercial_name,
                    ));
            } else {
                Log::warning("No se encontró un email de contacto para notificar la aprobación del pago ID: {$payment->id}");
            }
        } catch (\Exception $e) {
            Log::error("Fallo al enviar correo de aprobación al cliente: " . $e->getMessage());
            // No detenemos la redirección por un fallo de correo
        }
        // --- FIN: Notificar al Suscriptor ---

        return redirect()->route('admin.payments.index')->with('success', 'Pago aprobado y suscripción activada.');
    }

    /**
     * Rechaza un pago por transferencia.
     */
    public function reject(Request $request, SubscriptionPayment $payment)
    {
        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        if ($payment->status !== SubscriptionPaymentStatus::PENDING || $payment->payment_method !== 'transferencia') {
            return redirect()->route('admin.payments.index')->with('error', 'Este pago no se puede rechazar.');
        }

        try {
            $subscription = $payment->subscriptionVersion->subscription;

            DB::transaction(function () use ($payment, $validated, $subscription) {
                // 1. Marcamos el pago como rechazado
                $payment->update([
                    'status' => SubscriptionPaymentStatus::REJECTED,
                    'payment_details' => ['rejection_reason' => $validated['rejection_reason']]
                ]);

                // --- INICIO: LÓGICA DE GASTO (NUEVO) ---
                // 2. Buscar el gasto 'pendiente' que coincida con este pago
                $pendingExpense = Expense::where('status', ExpenseStatus::PENDING)
                    ->where('amount', $payment->amount) // Coincidir monto
                    ->where('description', 'like', 'Pago de suscripción%') // Coincidir descripción
                    ->whereHas('branch.subscription', fn($q) => $q->where('id', $subscription->id)) // Coincidir suscripción
                    ->latest('created_at')
                    ->first();

                // 3. Si se encuentra, eliminarlo
                if ($pendingExpense) {
                    $pendingExpense->delete();
                }
                // --- FIN: LÓGICA DE GASTO ---
            });
        } catch (\Exception $e) {
            Log::error("Error al rechazar pago: " . $e->getMessage());
            return redirect()->route('admin.payments.index')->with('error', 'Error al rechazar el pago: ' . $e->getMessage());
        }

        // --- INICIO: Notificar al Suscriptor (Rechazado) ---
        try {
            $subscription = $payment->subscriptionVersion->subscription;
            $subscriptionEmail = $subscription->contact_email; 

            if ($subscriptionEmail) {
                Mail::to($subscriptionEmail)
                    ->send(new PaymentStatusNotification(
                        $payment,
                        'rejected',
                        $subscription->commercial_name,
                        $validated['rejection_reason']
                    ));
            } else {
                Log::warning("No se encontró un email de contacto para notificar el rechazo del pago ID: {$payment->id}");
            }
        } catch (\Exception $e) {
            Log::error("Fallo al enviar correo de rechazo al cliente: " . $e->getMessage());
            // No detenemos la redirección por un fallo de correo
        }
        // --- FIN: Notificar al Suscriptor ---

        return redirect()->route('admin.payments.index')->with('success', 'Pago rechazado exitosamente.');
    }
}
