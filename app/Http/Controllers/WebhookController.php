<?php

namespace App\Http\Controllers;

use App\Actions\Subscription\ApproveSubscriptionPaymentAction;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\SubscriptionPayment;
use App\Services\PlatformMercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Recibe notificaciones webhook de Mercado Pago para pagos de suscripción.
     * Endpoint público (sin auth ni CSRF) — registrado en routes/api.php.
     */
    public function mercadopago(Request $request, PlatformMercadoPagoService $mpService, ApproveSubscriptionPaymentAction $approveAction)
    {
        $payload = $request->all();

        Log::info('MP webhook received', ['type' => $payload['type'] ?? 'unknown', 'action' => $payload['action'] ?? 'unknown']);

        // Solo nos interesan notificaciones de pago
        if (($payload['type'] ?? '') !== 'payment') {
            return response()->json(['status' => 'ignored', 'reason' => 'not a payment notification']);
        }

        $mpPaymentId = $payload['data']['id'] ?? null;

        if (!$mpPaymentId) {
            Log::warning('MP webhook missing payment ID', ['payload' => $payload]);
            return response()->json(['status' => 'error', 'reason' => 'missing payment id'], 400);
        }

        try {
            // Consultar el estado real del pago a la API de MP
            $mpPayment = $mpService->getPayment((string) $mpPaymentId);
        } catch (\Exception $e) {
            Log::error('MP webhook: failed to fetch payment', [
                'mp_payment_id' => $mpPaymentId,
                'error'         => $e->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'reason' => 'failed to fetch payment'], 500);
        }

        $status = $mpPayment['status'] ?? '';
        $externalReference = $mpPayment['external_reference'] ?? '';

        if ($status !== 'approved') {
            Log::info('MP webhook: payment not approved', [
                'mp_payment_id' => $mpPaymentId,
                'status'         => $status,
            ]);
            return response()->json(['status' => 'ignored', 'reason' => "payment status: {$status}"]);
        }

        // Buscar nuestro SubscriptionPayment por external_reference
        $subscriptionPayment = SubscriptionPayment::find($externalReference);

        if (!$subscriptionPayment) {
            Log::warning('MP webhook: subscription payment not found', [
                'external_reference' => $externalReference,
                'mp_payment_id'      => $mpPaymentId,
            ]);
            return response()->json(['status' => 'error', 'reason' => 'subscription payment not found'], 404);
        }

        // Si ya está aprobado, no hacemos nada
        if ($subscriptionPayment->status === SubscriptionPaymentStatus::APPROVED) {
            return response()->json(['status' => 'ok', 'reason' => 'already approved']);
        }

        // Guardar datos de MP en payment_details antes de aprobar
        $currentDetails = $subscriptionPayment->payment_details ?? [];
        $currentDetails['mp_payment_id'] = $mpPaymentId;
        $currentDetails['mp_status'] = $status;
        $subscriptionPayment->update(['payment_details' => $currentDetails]);

        // Aprobar el pago
        try {
            $approveAction->execute($subscriptionPayment);
        } catch (\Exception $e) {
            Log::error('MP webhook: approval failed', [
                'subscription_payment_id' => $subscriptionPayment->id,
                'mp_payment_id'           => $mpPaymentId,
                'error'                   => $e->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'reason' => 'approval failed'], 500);
        }

        Log::info('MP webhook: payment approved via webhook', [
            'subscription_payment_id' => $subscriptionPayment->id,
            'mp_payment_id'           => $mpPaymentId,
        ]);

        return response()->json(['status' => 'ok']);
    }
}
