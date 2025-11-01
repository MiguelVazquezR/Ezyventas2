<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Enums\SubscriptionStatus;
use App\Enums\BillingPeriod;
use App\Enums\SubscriptionPaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            ->where('payment_method', 'transfer')
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
            ->latest('start_date') // Obtener la más reciente
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
     * AQUÍ ESTÁ LA LÓGICA DE FECHAS QUE PEDISTE.
     */
    public function approve(SubscriptionPayment $payment)
    {
        if ($payment->status !== SubscriptionPaymentStatus::PENDING || $payment->payment_method !== 'transfer') {
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
                    ->latest('start_date')
                    ->first();

                // --- INICIO: REGLA 4 - Lógica de Fechas de Renovación ---
                $startDate = now()->startOfDay(); // Default: iniciar hoy

                // Si hay una versión activa Y AÚN NO VENCE (pagó temprano)
                if ($currentActiveVersion && $currentActiveVersion->end_date->isFuture()) {
                    // La nueva versión empieza cuando la actual TERMINA
                    $startDate = $currentActiveVersion->end_date;
                }
                // Si ya expiró (pagó tarde) o no hay versión, $startDate se queda como 'now()'.
                // Esto cumple la regla: si paga el 26 (expiró el 25), empieza el 26.
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
            });
        } catch (\Exception $e) {
            Log::error("Error al aprobar pago: " . $e->getMessage());
            return redirect()->route('admin.payments.index')->with('error', 'Error al aprobar el pago: ' . $e->getMessage());
        }

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

        if ($payment->status !== SubscriptionPaymentStatus::PENDING || $payment->payment_method !== 'transfer') {
            return redirect()->route('admin.payments.index')->with('error', 'Este pago no se puede rechazar.');
        }

        try {
            // --- INICIO: REGLA 3 - No borrar versión ---
            DB::transaction(function () use ($payment, $validated) {
                // 1. Marcamos el pago como rechazado
                $payment->update([
                    'status' => SubscriptionPaymentStatus::REJECTED,
                    'payment_details' => ['rejection_reason' => $validated['rejection_reason']]
                ]);
            });
        } catch (\Exception $e) {
            Log::error("Error al rechazar pago: " . $e->getMessage());
            return redirect()->route('admin.payments.index')->with('error', 'Error al rechazar el pago: ' . $e->getMessage());
        }

        return redirect()->route('admin.payments.index')->with('success', 'Pago rechazado exitosamente.');
    }
}