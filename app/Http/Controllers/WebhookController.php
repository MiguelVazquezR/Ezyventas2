<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ApproveStampPurchaseAction;
use App\Actions\Subscription\ApproveSubscriptionPaymentAction;
use App\Enums\StampPurchaseStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\Billing\StampPurchase;
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
        $isLiveMode = $payload['live_mode'] ?? false;

        // Si es una simulación/prueba de MP (live_mode=false), solo confirmamos recepción
        if (!$isLiveMode) {
            Log::info('MP webhook simulation received — acknowledged');
            return response()->json(['status' => 'ok', 'reason' => 'simulation acknowledged']);
        }

        // 0. Validar firma HMAC del webhook (previene llamadas no autorizadas)
        if (!$this->validateSignature($request)) {
            Log::warning('MP webhook: signature validation failed');
            return response()->json(['status' => 'error', 'reason' => 'invalid signature'], 401);
        }

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

        // ── Try subscription payment first ──
        $subscriptionPayment = SubscriptionPayment::find($externalReference);

        if ($subscriptionPayment) {
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

        // ── Try stamp purchase ──
        $stampPurchase = StampPurchase::find($externalReference);

        if ($stampPurchase) {
            if ($stampPurchase->isStampsApplied()) {
                return response()->json(['status' => 'ok', 'reason' => 'stamps already applied']);
            }

            $stampPurchase->update([
                'mp_payment_id' => $mpPaymentId,
            ]);

            // If this purchase requires review (large_quantity), don't auto-approve.
            // The payment is confirmed, but the superadmin must review before stamps are applied.
            if ($stampPurchase->review_reason === 'large_quantity') {
                $stampPurchase->update([
                    'status' => StampPurchaseStatus::AWAITING_REVIEW,
                ]);

                Log::info('MP webhook: large stamp purchase awaiting review', [
                    'stamp_purchase_id' => $stampPurchase->id,
                    'mp_payment_id'     => $mpPaymentId,
                    'quantity'          => $stampPurchase->stamp_quantity,
                ]);

                return response()->json(['status' => 'ok', 'reason' => 'awaiting review — large quantity']);
            }

            // Normal purchase: auto-approve and dispatch PAC job
            $stampPurchase->update([
                'status' => StampPurchaseStatus::APPROVED,
            ]);

            $stampApproveAction = app(ApproveStampPurchaseAction::class);
            $stampApproveAction->execute($stampPurchase, $stampPurchase->requested_by_user_id);

            Log::info('MP webhook: stamp purchase approved', [
                'stamp_purchase_id' => $stampPurchase->id,
                'mp_payment_id'     => $mpPaymentId,
            ]);

            return response()->json(['status' => 'ok']);
        }

        Log::warning('MP webhook: neither subscription payment nor stamp purchase found', [
            'external_reference' => $externalReference,
            'mp_payment_id'      => $mpPaymentId,
        ]);

        return response()->json(['status' => 'error', 'reason' => 'payment record not found'], 404);
    }

    /**
     * Valida la firma HMAC del webhook de Mercado Pago.
     *
     * Algoritmo oficial sin SDK:
     * 1. Extraer ts y v1 del header x-signature
     * 2. Construir manifest: "id:{data.id};request-id:{x-request-id};ts:{ts};"
     * 3. Calcular HMAC-SHA256 con la clave secreta configurada en MP_WEBHOOK_SECRET
     * 4. Comparar con v1
     *
     * @see https://www.mercadopago.com.mx/developers/es/docs/checkout-pro/payment-notifications
     */
    private function validateSignature(Request $request): bool
    {
        $secret = config('services.mercadopago.webhook_secret');

        // Si no hay secreto configurado, saltamos validación (entorno dev/local)
        if (empty($secret)) {
            Log::warning('MP webhook: no webhook_secret configured, skipping signature validation');
            return true;
        }

        $xSignature = $request->header('x-signature');
        $xRequestId = $request->header('x-request-id');

        if (empty($xSignature)) {
            return false;
        }

        // Extraer ts y v1 del header x-signature (formato: "ts=1234,v1=abcd...")
        $parts = explode(',', $xSignature);
        $ts = null;
        $v1 = null;

        foreach ($parts as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) continue;

            $key = trim($pair[0]);
            $value = trim($pair[1]);

            if ($key === 'ts') $ts = $value;
            elseif ($key === 'v1') $v1 = $value;
        }

        if (!$ts || !$v1) {
            return false;
        }

        // Obtener data.id de los query params (MP lo envía como ?data.id=xxx)
        $dataId = $request->query('data.id', '');

        // Construir manifest string
        $manifestParts = [];
        if ($dataId !== '') {
            $manifestParts[] = "id:{$dataId}";
        }
        if (!empty($xRequestId)) {
            $manifestParts[] = "request-id:{$xRequestId}";
        }
        $manifestParts[] = "ts:{$ts}";
        $manifest = implode(';', $manifestParts) . ';';

        // Calcular HMAC-SHA256
        $computedHash = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($computedHash, $v1);
    }
}
