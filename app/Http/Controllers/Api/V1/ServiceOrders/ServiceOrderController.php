<?php

namespace App\Http\Controllers\Api\V1\ServiceOrders;

use App\Actions\ServiceOrders\ChangeServiceOrderStatusAction;
use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Actions\ServiceOrders\DeleteServiceOrderAction;
use App\Actions\ServiceOrders\EnsureServiceOrderTransactionAction;
use App\Actions\ServiceOrders\SaveServiceOrderDiagnosisAction;
use App\Actions\ServiceOrders\UpdateServiceOrderAction;
use App\Http\Controllers\Api\V1\Concerns\ResolvesOpenCashRegisterSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ServiceOrders\DestroyServiceOrderRequest;
use App\Http\Requests\Api\V1\ServiceOrders\EnsureServiceOrderTransactionRequest;
use App\Http\Requests\Api\V1\ServiceOrders\IndexServiceOrderRequest;
use App\Http\Requests\Api\V1\ServiceOrders\ShowServiceOrderRequest;
use App\Http\Requests\Api\V1\ServiceOrders\StoreServiceOrderDiagnosisRequest;
use App\Http\Requests\Api\V1\ServiceOrders\StoreServiceOrderPaymentRequest;
use App\Http\Requests\Api\V1\ServiceOrders\StoreServiceOrderRequest;
use App\Http\Requests\Api\V1\ServiceOrders\UpdateServiceOrderRequest;
use App\Http\Requests\Api\V1\ServiceOrders\UpdateServiceOrderStatusRequest;
use App\Enums\TransactionStatus;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Services\ServiceOrders\ServiceOrderReadService;
use App\Services\TransactionPaymentService;
use App\Services\Transactions\TransactionReadService;
use App\Services\WhatsAppTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Service orders: the technician work list, the order detail, creating and
 * editing orders, the status change, the diagnosis with evidence photos and the
 * advance payments.
 */
class ServiceOrderController extends Controller
{
    use ResolvesOpenCashRegisterSession;

    public function __construct(
        private readonly ServiceOrderReadService $serviceOrders,
        private readonly TransactionReadService $transactions,
        private readonly TransactionPaymentService $payments,
        private readonly WhatsAppTicketService $whatsAppTickets,
    ) {}

    public function index(IndexServiceOrderRequest $request): JsonResponse
    {
        $branchId = (int) $request->user()->branch_id;

        $serviceOrders = $this->serviceOrders
            ->queryForBranch($branchId, $request->filters())
            ->paginate($request->perPage())
            ->withQueryString();

        return response()->json(
            $serviceOrders->through(fn (ServiceOrder $serviceOrder) => $this->serviceOrders->listPayload($serviceOrder))
        );
    }

    public function show(ShowServiceOrderRequest $request, int $serviceOrderId): JsonResponse
    {
        $serviceOrder = $this->findOrFail($serviceOrderId, (int) $request->user()->branch_id);

        return response()->json($this->serviceOrders->detailPayload($serviceOrder));
    }

    public function updateStatus(
        UpdateServiceOrderStatusRequest $request,
        int $serviceOrderId,
        ChangeServiceOrderStatusAction $action,
    ): JsonResponse {
        $serviceOrder = $this->findOrFail($serviceOrderId, (int) $request->user()->branch_id);

        $result = $action->execute($serviceOrder, $request->status(), $request->user());

        if (!$result['success']) {
            throw ValidationException::withMessages(['status' => $result['message']]);
        }

        return response()->json([
            'service_order' => $this->serviceOrders->listPayload($serviceOrder->refresh()),
            'message' => $result['message'],
        ]);
    }

    public function storeDiagnosis(
        StoreServiceOrderDiagnosisRequest $request,
        int $serviceOrderId,
        SaveServiceOrderDiagnosisAction $action,
    ): JsonResponse {
        $serviceOrder = $this->findOrFail($serviceOrderId, (int) $request->user()->branch_id);

        $serviceOrder = $action->execute(
            $serviceOrder,
            $request->safe()->only('technician_diagnosis'),
            $request->file('closing_evidence_images') ?? []
        );

        return response()->json([
            'message' => 'Diagnóstico y evidencias guardados correctamente.',
            'service_order' => $this->serviceOrders->detailPayload($serviceOrder),
        ]);
    }

    public function store(StoreServiceOrderRequest $request, CreateServiceOrderAction $action): JsonResponse
    {
        $user = $request->user();
        $sessionId = (int) $request->validated('cash_register_session_id');

        if (!$this->openCashRegisterSession($sessionId, (int) $user->branch_id)) {
            return $this->sessionRequiredResponse('Necesitas una sesión de caja abierta en tu sucursal.');
        }

        $serviceOrder = $action->execute(
            $request->orderData(),
            $user,
            $request->file('initial_evidence_images') ?? []
        );

        return response()->json([
            'message' => 'Orden de servicio creada.',
            'service_order' => $this->detailOf($serviceOrder->id, (int) $user->branch_id),
        ], 201);
    }

    public function update(UpdateServiceOrderRequest $request, int $serviceOrderId, UpdateServiceOrderAction $action): JsonResponse
    {
        $user = $request->user();
        $serviceOrder = $this->findOrFail($serviceOrderId, (int) $user->branch_id);

        $action->execute(
            $serviceOrder,
            $request->orderData(),
            $user,
            $request->file('initial_evidence_images') ?? [],
            $request->validated('deleted_media_ids') ?? []
        );

        return response()->json([
            'message' => 'Orden de servicio actualizada.',
            'service_order' => $this->detailOf($serviceOrder->id, (int) $user->branch_id),
        ]);
    }

    public function ensureTransaction(
        EnsureServiceOrderTransactionRequest $request,
        int $serviceOrderId,
        EnsureServiceOrderTransactionAction $action,
    ): JsonResponse {
        $serviceOrder = $this->findOrFail($serviceOrderId, (int) $request->user()->branch_id);

        $transaction = $action->execute($serviceOrder, (int) $request->user()->id);

        return response()->json(['transaction_id' => $transaction->id]);
    }

    public function storePayment(
        StoreServiceOrderPaymentRequest $request,
        int $serviceOrderId,
        EnsureServiceOrderTransactionAction $ensureTransaction,
    ): JsonResponse {
        $user = $request->user();
        $sessionId = (int) $request->validated('cash_register_session_id');

        if (!$this->openCashRegisterSession($sessionId, (int) $user->branch_id)) {
            return $this->sessionRequiredResponse('Necesitas una sesión de caja abierta para registrar anticipos.');
        }

        $serviceOrder = $this->findOrFail($serviceOrderId, (int) $user->branch_id);

        // Old orders may not have a sale yet: the advance creates it.
        $transaction = $ensureTransaction->execute($serviceOrder, (int) $user->id);

        if (in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED], true)) {
            return response()->json([
                'code' => 'already_cancelled',
                'message' => 'No se pueden agregar pagos a transacciones canceladas o reembolsadas.',
            ], 422);
        }

        $previousDue = (float) $transaction->remaining_due;
        $customer = $transaction->customer;
        $usedBalance = ($request->validated('use_balance') && $customer)
            ? min((float) $customer->balance, $previousDue)
            : 0.0;

        try {
            $this->payments->applyPaymentToTransaction($transaction, $request->paymentData(), $sessionId);
        } catch (\Exception $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'service_order' => $this->detailOf($serviceOrder->id, (int) $user->branch_id),
            'transaction' => $this->transactions->detailPayload(
                $this->transactions->findForBranch($transaction->id, (int) $user->branch_id)
            ),
            'print' => $this->paymentReceipt($transaction, $previousDue, $request->validated('payments') ?? [], $usedBalance),
        ]);
    }

    public function destroy(DestroyServiceOrderRequest $request, int $serviceOrderId, DeleteServiceOrderAction $action): Response
    {
        $serviceOrder = $this->findOrFail($serviceOrderId, (int) $request->user()->branch_id);

        $action->execute($serviceOrder);

        return response()->noContent();
    }

    /**
     * Full detail of the order as the app expects it.
     *
     * @return array<string, mixed>
     */
    private function detailOf(int $serviceOrderId, int $branchId): array
    {
        return $this->serviceOrders->detailPayload($this->findOrFail($serviceOrderId, $branchId));
    }

    /**
     * Receipt of the advance, ready to send by WhatsApp.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<string, mixed>
     */
    private function paymentReceipt(Transaction $transaction, float $previousDue, array $payments, float $usedBalance): array
    {
        $customer = $transaction->customer;

        return [
            'type' => 'abono',
            'payload' => $this->whatsAppTickets->buildTransactionAbonoPayload($transaction, $previousDue, $payments, $usedBalance),
            'transaction_id' => $transaction->id,
            'customer_phone' => $customer?->phone,
            'customer_id' => $customer?->id,
        ];
    }

    private function findOrFail(int $serviceOrderId, int $branchId): ServiceOrder
    {
        $serviceOrder = $this->serviceOrders->findForBranch($serviceOrderId, $branchId);

        if (!$serviceOrder) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        return $serviceOrder;
    }
}
