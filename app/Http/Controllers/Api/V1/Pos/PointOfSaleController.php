<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Actions\Pos\CreateStoreOrderAction;
use App\Enums\CustomerBalanceMovementType;
use App\Enums\PaymentMethod;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\Concerns\ResolvesOpenCashRegisterSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Pos\CreateLayawayRequest;
use App\Http\Requests\Api\V1\Pos\CreateStoreOrderRequest;
use App\Http\Requests\Api\V1\Pos\RegisterSaleRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionPaymentService;
use App\Services\Transactions\TransactionReadService;
use Illuminate\Http\JsonResponse;

/**
 * Sale registration from the phone: checkout, layaway and order (pedido).
 *
 * All three reuse the services the web POS uses, so the stock, the customer
 * debt and the cash register totals stay identical no matter the client.
 */
class PointOfSaleController extends Controller
{
    use ResolvesOpenCashRegisterSession;

    public function __construct(
        private readonly TransactionPaymentService $payments,
        private readonly TransactionReadService $transactions,
        private readonly CreateStoreOrderAction $createStoreOrder,
    ) {}

    public function checkout(RegisterSaleRequest $request): JsonResponse
    {
        return $this->registerSale($request, TransactionStatus::PENDING, CustomerBalanceMovementType::CREDIT_SALE);
    }

    public function layaway(CreateLayawayRequest $request): JsonResponse
    {
        return $this->registerSale($request, TransactionStatus::ON_LAYAWAY, CustomerBalanceMovementType::LAYAWAY_DEBT);
    }

    public function storeOrder(CreateStoreOrderRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->openCashRegisterSession((int) $request->validated('cash_register_session_id'), $user->branch_id)) {
            return $this->sessionRequiredResponse('Necesitas una sesión de caja abierta para registrar pedidos.');
        }

        $transaction = $this->createStoreOrder->execute($request->orderData(), $user);

        return response()->json([
            'transaction' => $this->transactionPayload($transaction, $user),
            'print' => $this->printPayload($transaction, $user, 'order'),
        ], 201);
    }

    /**
     * Registers a cash sale or a layaway depending on the initial status.
     */
    private function registerSale(
        RegisterSaleRequest $request,
        TransactionStatus $status,
        CustomerBalanceMovementType $debtType,
    ): JsonResponse {
        $user = $request->user();

        if (!$this->openCashRegisterSession((int) $request->validated('cash_register_session_id'), $user->branch_id)) {
            return $this->sessionRequiredResponse();
        }

        $customer = $request->customer();
        $creditAmount = $request->pendingCreditAmount($customer);

        if ($creditAmount > 0.01) {
            if (!$customer) {
                return response()->json([
                    'code' => 'customer_required',
                    'message' => 'Selecciona un cliente para dejar saldo pendiente.',
                ], 422);
            }

            if ($creditAmount > (float) $customer->available_credit) {
                return response()->json([
                    'code' => 'credit_limit_exceeded',
                    'message' => 'El cliente no tiene crédito disponible suficiente.',
                ], 422);
            }
        }

        try {
            $transaction = $this->payments->handleNewSale($request->saleData(), $user, $customer, $status, $debtType);
        } catch (\Exception $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'transaction' => $this->transactionPayload($transaction, $user),
            'change' => $this->cashChange($request),
            'print' => $this->printPayload($transaction, $user, 'pos'),
        ], 201);
    }

    /**
     * Cash change to give back: only when the sale was paid in cash alone.
     */
    private function cashChange(RegisterSaleRequest $request): float
    {
        $payments = collect($request->validated('payments') ?? []);

        if ($payments->isEmpty() || $payments->contains(fn (array $payment) => $payment['method'] !== PaymentMethod::CASH->value)) {
            return 0.0;
        }

        return max(0, round($payments->sum('amount') - (float) $request->validated('total'), 2));
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(Transaction $transaction, User $user): array
    {
        return $this->transactions->detailPayload(
            $this->transactions->findForBranch($transaction->id, (int) $user->branch_id)
        );
    }

    /**
     * Print hint for the app: the receipt itself is rendered by the server in
     * phase 4, so the app only needs which templates are available.
     *
     * @return array<string, mixed>
     */
    private function printPayload(Transaction $transaction, User $user, string $dataSourceType): array
    {
        return [
            'data_source_type' => $dataSourceType,
            'data_source_id' => $transaction->id,
            'template_ids' => $user->branch->printTemplates()
                ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
                ->whereIn('context_type', [TemplateContextType::POS, TemplateContextType::GENERAL])
                ->pluck('print_templates.id')
                ->all(),
        ];
    }
}
