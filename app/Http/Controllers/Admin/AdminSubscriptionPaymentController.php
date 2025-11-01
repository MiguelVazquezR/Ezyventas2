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
        $payment->load([
            'subscriptionVersion.subscription:id,commercial_name', // Cargar solo info necesaria
            'subscriptionVersion.items',
            'media'
        ]);

        $proofUrl = null;
        if ($payment->hasMedia('proof_of_payment')) {
            // --- CORRECCIÓN ---
            // Cambiamos getTemporaryUrl() por getUrl()
            // Esto asume que estás usando el disco 'public' y
            // que ejecutaste `php artisan storage:link`.
            $proofUrl = $payment->getFirstMedia('proof_of_payment')->getUrl();
        }

        return Inertia::render('Admin/Payment/Show', [
            'payment' => $payment,
            'proofUrl' => $proofUrl,
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
                $newVersion = $payment->subscriptionVersion;
                $subscription = $newVersion->subscription;
                
                // Obtener el periodo de facturación (mes o año) de la NUEVA versión pendiente
                $billingPeriod = $newVersion->items->first()->billing_period; 

                // --- INICIO DE LÓGICA DE FECHAS ---
                
                // 1. Buscar la última versión VÁLIDA (vigente) de la suscripción
                $latestActiveVersion = $subscription->versions()
                    ->where('id', '!=', $newVersion->id) // Excluir la que estamos aprobando
                    ->where('end_date', '>', now()) // Que esté vigente
                    ->latest('end_date') // Obtener la que termina más tarde
                    ->first();

                $startDate = null;
                $endDate = null;
                
                // 2. Determinar fechas basadas en si la suscripción ESTÁ VIGENTE o YA EXPIRÓ
                
                if ($latestActiveVersion) {
                    // REGLA: "Si paga ANTES de que termine su periodo, los días se suman"
                    // La nueva versión empieza cuando la activa TERMINA.
                    $startDate = $latestActiveVersion->end_date;
                    $endDate = ($billingPeriod === BillingPeriod::ANNUALLY)
                        ? $startDate->copy()->addYear()
                        : $startDate->copy()->addMonth();
                    
                    Log::info("Aprobando renovación para suscripción activa. Nuevo periodo: $startDate a $endDate");

                } else {
                    // REGLA: "Si ya expiró, se tomará el inicio el día que pagó (aprobó)"
                    // La nueva versión empieza HOY (momento de aprobación).
                    $startDate = now();
                    $endDate = ($billingPeriod === BillingPeriod::ANNUALLY)
                        ? $startDate->copy()->addYear()
                        : $startDate->copy()->addMonth();

                    Log::info("Aprobando renovación para suscripción EXPIRADA. Nuevo periodo: $startDate a $endDate");
                }
                // --- FIN DE LÓGICA DE FECHAS ---

                // 3. Actualizar la versión PENDIENTE con las fechas correctas
                $newVersion->update([
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]);

                // 4. Actualizar el pago
                $payment->update(['status' => SubscriptionPaymentStatus::APPROVED]);

                // 5. Activar la suscripción general
                $subscription->update(['status' => SubscriptionStatus::ACTIVE]);

                // 6. (Opcional pero recomendado) Asegurarse de que no haya traslapes
                // Si la versión activa se traslapaba, se corta hoy.
                if ($latestActiveVersion && $latestActiveVersion->end_date > $startDate) {
                     $latestActiveVersion->update(['end_date' => $startDate]);
                }
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
            DB::transaction(function () use ($payment, $validated) {
                // 1. Actualizar el pago con el motivo
                $payment->update([
                    'status' => SubscriptionPaymentStatus::REJECTED,
                    'payment_details' => ['rejection_reason' => $validated['rejection_reason']]
                ]);

                // 2. IMPORTANTE: NO borramos la versión, solo el pago.
                // PERO SÍ debemos borrar el comprobante para que suba uno nuevo.
                // El usuario podrá reintentar subir el comprobante sobre este mismo pago.
                // Opcional: Podrías borrar la versión si prefieres que inicie de cero.
                
                // $payment->subscriptionVersion->items()->delete();
                // $payment->subscriptionVersion->delete();

                // 3. Borrar el comprobante
                $payment->clearMediaCollection('proof_of_payment');

                // 4. Poner el pago de nuevo en PENDIENTE pero sin archivo
                // (Esto es un flujo alternativo, si quieres que el usuario VUELVA a subir)
                // O mejor, lo dejamos en REJECTED, y la vista `Show.vue` le dirá
                // que inicie el proceso de nuevo. Esto es más limpio.
            });

        } catch (\Exception $e) {
            Log::error("Error al rechazar pago: " . $e->getMessage());
            return redirect()->route('admin.payments.index')->with('error', 'Error al rechazar el pago: ' . $e->getMessage());
        }
        
        return redirect()->route('admin.payments.index')->with('success', 'Pago rechazado exitosamente.');
    }
}