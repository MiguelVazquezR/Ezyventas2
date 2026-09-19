<?php

namespace App\Http\Controllers\Api\V1\Transactions;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\Concerns\ResolvesOpenCashRegisterSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Transactions\CancelTransactionRequest;
use App\Http\Requests\Api\V1\Transactions\DeleteTransactionPaymentRequest;
use App\Http\Requests\Api\V1\Transactions\IndexTransactionRequest;
use App\Http\Requests\Api\V1\Transactions\RefundTransactionRequest;
use App\Http\Requests\Api\V1\Transactions\ShowTransactionRequest;
use App\Http\Requests\Api\V1\Transactions\StoreTransactionPaymentRequest;
use App\Http\Requests\Api\V1\Transactions\UpdateTransactionPaymentRequest;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\TransactionPaymentService;
use App\Services\Transactions\TransactionCancellationService;
use App\Services\Transactions\TransactionPaymentEditService;
use App\Services\Transactions\TransactionReadService;
use App\Services\WhatsAppTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sales of the branch: history, detail, payments (abonos), cancellation and
 * refund. Stock, debt and till movements are delegated to the same services the
 * web uses.
 */
class TransactionController extends Controller
{
    use ResolvesOpenCashRegisterSession;

    public function __construct(
        private readonly TransactionReadService $transactions,
        private readonly TransactionPaymentService $payments,
        private readonly TransactionCancellationService $cancellations,
        private readonly TransactionPaymentEditService $paymentEditor,
        private readonly WhatsAppTicketService $whatsAppTickets,
    ) {}

    public function index(IndexTransactionRequest $request): JsonResponse
    {
        $branchId = (int) $request->user()->branch_id;

        $transactions = $this->transactions
            ->queryForBranch($branchId, $request->filters())
            ->paginate($request->perPage())
            ->withQueryString();

        return response()->json(
            $transactions->through(fn (Transaction $transaction) => $this->transactions->listPayload($transaction))
        );
    }

    public function show(ShowTransactionRequest $request, int $transactionId): JsonResponse
    {
        $transaction = $this->findOrFail($transactionId, (int) $request->user()->branch_id);

        return response()->json($this->transactions->detailPayload($transaction));
    }

    public function addPayment(StoreTransactionPaymentRequest $request, int $transactionId): JsonResponse
    {
        $user = $request->user();
        $sessionId = (int) $request->validated('cash_register_session_id');

        if (!$this->openCashRegisterSession($sessionId, (int) $user->branch_id)) {
            return $this->sessionRequiredResponse('Necesitas una sesión de caja abierta para registrar abonos.');
        }

        $transaction = $this->findOrFail($transactionId, (int) $user->branch_id);

        if (in_array($transaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED], true)) {
            return response()->json([
                'code' => 'already_cancelled',
                'message' => 'No se pueden agregar pagos a transacciones canceladas o reembolsadas.',
            ], 422);
        }

        // Pending balance before the payment: it is what the receipt shows.
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
            'transaction' => $this->transactions->detailPayload($this->findForBranch($transaction->id, (int) $user->branch_id)),
            'print' => $this->paymentReceipt(
                $transaction,
                $previousDue,
                $request->validated('payments') ?? [],
                $usedBalance
            ),
        ]);
    }

    public function cancel(CancelTransactionRequest $request, int $transactionId): JsonResponse
    {
        return $this->cancelTransaction(
            $request,
            $transactionId,
            (string) $request->validated('action'),
            $request->validated('refund_method'),
            $request->validated('bank_account_id')
        );
    }

    public function refund(RefundTransactionRequest $request, int $transactionId): JsonResponse
    {
        return $this->cancelTransaction(
            $request,
            $transactionId,
            'refund',
            (string) $request->validated('refund_method'),
            $request->validated('bank_account_id')
        );
    }

    /**
     * Shared flow of cancel and refund: the service validates the business
     * rules (open session for cash, customer for balance, ...).
     */
    private function cancelTransaction(
        CancelTransactionRequest|RefundTransactionRequest $request,
        int $transactionId,
        string $action,
        ?string $refundMethod,
        ?int $bankAccountId,
    ): JsonResponse {
        $user = $request->user();
        $transaction = $this->findOrFail($transactionId, (int) $user->branch_id);

        $message = $this->cancellations->cancel($transaction, $action, $refundMethod, $bankAccountId, $user);

        return response()->json([
            'transaction' => $this->transactions->detailPayload($this->findForBranch($transaction->id, (int) $user->branch_id)),
            'message' => $message,
        ]);
    }

    public function updatePayment(UpdateTransactionPaymentRequest $request, int $transactionId, int $paymentId): JsonResponse
    {
        $user = $request->user();
        $transaction = $this->findOrFail($transactionId, (int) $user->branch_id);
        $payment = $this->findPaymentOrFail($transaction, $paymentId);

        $payment = $this->paymentEditor->update($transaction, $payment, $request->validated());

        return response()->json([
            'message' => 'Pago actualizado correctamente.',
            'payment' => $this->paymentPayload($payment),
            'transaction' => $this->transactions->detailPayload($this->findForBranch($transaction->id, (int) $user->branch_id)),
        ]);
    }

    public function destroyPayment(DeleteTransactionPaymentRequest $request, int $transactionId, int $paymentId): Response
    {
        $user = $request->user();
        $transaction = $this->findOrFail($transactionId, (int) $user->branch_id);
        $payment = $this->findPaymentOrFail($transaction, $paymentId);

        $this->paymentEditor->delete($transaction, $payment);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => (float) $payment->amount,
            'payment_method' => $payment->payment_method instanceof \BackedEnum
                ? $payment->payment_method->value
                : $payment->payment_method,
            'status' => $payment->status instanceof \BackedEnum ? $payment->status->value : $payment->status,
            'bank_account_id' => $payment->bank_account_id,
            'notes' => $payment->notes,
            'payment_date' => $payment->payment_date?->toIso8601String(),
        ];
    }

    private function findPaymentOrFail(Transaction $transaction, int $paymentId): Payment
    {
        $payment = $transaction->payments()->find($paymentId);

        if (!$payment) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        return $payment;
    }

    /**
     * Receipt data of the payment: the same payload the web sends to WhatsApp.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<string, mixed>
     */
    private function paymentReceipt(Transaction $transaction, float $previousDue, array $payments, float $usedBalance): array
    {
        $customer = $transaction->customer;
        $contactInfo = $transaction->contact_info;
        $contactPhone = is_array($contactInfo) && !empty($contactInfo['phone'])
            ? trim((string) $contactInfo['phone'])
            : null;
        $isOrder = $transaction->isOrder();

        $payload = $isOrder
            ? $this->whatsAppTickets->buildOrderPaymentPayload($transaction, $previousDue, $payments, $usedBalance)
            : $this->whatsAppTickets->buildTransactionAbonoPayload($transaction, $previousDue, $payments, $usedBalance);

        return [
            'type' => $isOrder ? 'order_payment' : 'abono',
            'payload' => $payload,
            'transaction_id' => $transaction->id,
            'customer_phone' => $isOrder ? ($contactPhone ?: $customer?->phone) : $customer?->phone,
            'customer_id' => $customer?->id,
        ];
    }

    private function findOrFail(int $transactionId, int $branchId): Transaction
    {
        $transaction = $this->findForBranch($transactionId, $branchId);

        if (!$transaction) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        return $transaction;
    }

    private function findForBranch(int $transactionId, int $branchId): ?Transaction
    {
        return $this->transactions->findForBranch($transactionId, $branchId);
    }
}
