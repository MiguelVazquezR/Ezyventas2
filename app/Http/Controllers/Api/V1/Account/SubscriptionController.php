<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Account\StoreSubscriptionDocumentRequest;
use App\Http\Requests\Api\V1\Account\SubscriptionInvoiceRequest;
use App\Http\Requests\Api\V1\Account\SubscriptionRequest;
use App\Http\Requests\Api\V1\Account\UpdateSubscriptionRequest;
use App\Models\SubscriptionPayment;
use App\Services\Account\SubscriptionReadService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Subscription screen: plan, usage, status, payment history and the fiscal
 * document. Owners only.
 *
 * Renewing or upgrading the plan is NOT reimplemented here: the app opens the
 * web checkout in the browser (see the contract, §11b.5).
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionReadService $subscriptionReader) {}

    public function show(SubscriptionRequest $request): JsonResponse
    {
        return response()->json(
            $this->subscriptionReader->payload($request->user()->branch->subscription)
        );
    }

    public function update(UpdateSubscriptionRequest $request): JsonResponse
    {
        $subscription = $request->user()->branch->subscription;
        $subscription->update($request->validated());

        return response()->json([
            'message' => 'Los datos de la suscripción se guardaron.',
            'subscription' => $this->subscriptionReader->payload($subscription->fresh())['subscription'],
        ]);
    }

    public function storeDocument(StoreSubscriptionDocumentRequest $request): JsonResponse
    {
        $subscription = $request->user()->branch->subscription;

        $subscription->clearMediaCollection('fiscal-documents');
        $subscription->addMediaFromRequest('fiscal_document')->toMediaCollection('fiscal-documents');

        return response()->json([
            'message' => 'Documento fiscal actualizado con éxito.',
            'fiscal_document_url' => $subscription->fresh()->getFirstMediaUrl('fiscal-documents') ?: null,
        ]);
    }

    public function requestInvoice(SubscriptionInvoiceRequest $request, int $paymentId): JsonResponse
    {
        $subscription = $request->user()->branch->subscription;

        $payment = SubscriptionPayment::whereHas(
            'subscriptionVersion',
            fn ($query) => $query->where('subscription_id', $subscription->id)
        )->find($paymentId);

        if (!$payment) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        if ($payment->status !== SubscriptionPaymentStatus::APPROVED) {
            return response()->json([
                'code' => 'payment_not_approved',
                'message' => 'Solo puedes solicitar facturas de pagos aprobados.',
            ], 403);
        }

        if ($payment->invoice_status === InvoiceStatus::NOT_REQUESTED) {
            $payment->update(['invoice_status' => InvoiceStatus::REQUESTED]);

            return response()->json(['message' => 'Factura solicitada. Nos pondremos en contacto pronto.']);
        }

        return response()->json(['message' => 'Esta factura ya ha sido solicitada o generada.']);
    }
}
