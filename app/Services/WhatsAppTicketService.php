<?php

namespace App\Services;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Transaction;
use Carbon\Carbon;

/**
 * Construye los payloads de los tickets de WhatsApp:
 * - Tickets de venta (contado / crédito / apartado): PrintController::whatsappTicket.
 * - Tickets de abono (a venta particular o a la cuenta general del cliente):
 *   los arman los controladores al registrar el abono y viajan por print_data.
 */
class WhatsAppTicketService
{
    /**
     * Formatea los métodos de pago con montos: "Efectivo: $30.00, Tarjeta: $22.50".
     *
     * @param array $payments Lista de pagos [{ method, amount }, ...]
     */
    public function formatPaymentBreakdown(array $payments): string
    {
        if (empty($payments)) {
            return '';
        }

        $totals = [];
        foreach ($payments as $payment) {
            $method = $payment['method'] ?? '';
            $amount = (float) ($payment['amount'] ?? 0);
            $totals[$method] = ($totals[$method] ?? 0) + $amount;
        }

        $parts = [];
        foreach ($totals as $method => $amount) {
            $parts[] = $this->methodLabel($method) . ': $' . number_format($amount, 2);
        }

        return implode(', ', $parts);
    }

    private function methodLabel(string $method): string
    {
        return match ($method) {
            'efectivo' => 'Efectivo',
            'tarjeta' => 'Tarjeta',
            'transferencia' => 'Transferencia',
            'saldo' => 'Saldo a favor',
            'intercambio' => 'Intercambio',
            default => ucfirst($method),
        };
    }

    /**
     * Payload del ticket de abono a una venta en particular.
     *
     * @param Transaction $transaction Transacción abonada.
     * @param float $previousDue Saldo pendiente ANTES del abono.
     * @param array $payments Pagos registrados en este abono.
     * @param float $usedBalance Saldo a favor del cliente usado como pago.
     */
    public function buildTransactionAbonoPayload(
        Transaction $transaction,
        float $previousDue,
        array $payments,
        float $usedBalance = 0.0
    ): array {
        $customer = $transaction->customer;
        $subscription = $transaction->branch?->subscription;
        $fresh = $transaction->fresh();
        $remaining = round((float) $fresh->remaining_due, 2);
        $abonado = round((float) collect($payments)->sum('amount') + $usedBalance, 2);

        // El saldo a favor usado también se muestra como método de pago en el ticket.
        $breakdownPayments = $payments;
        if ($usedBalance > 0) {
            $breakdownPayments[] = ['method' => 'saldo', 'amount' => $usedBalance];
        }

        return [
            'kind' => 'abono',
            'scope' => 'transaction',
            'businessName' => $subscription?->commercial_name ?: ($transaction->branch?->name ?: 'Mi Negocio'),
            'date' => now()->format('d/m/Y - H:i'),
            'customer' => $customer?->name ?: 'Público en General',
            'folio' => $transaction->folio,
            'saleTotal' => '$' . number_format((float) $fresh->total, 2) . ' MXN',
            'previousDue' => '$' . number_format($previousDue, 2) . ' MXN',
            'abonado' => '$' . number_format($abonado, 2) . ' MXN',
            'remainingDue' => '$' . number_format($remaining, 2) . ' MXN',
            'liquidated' => $remaining <= 0.01,
            'expirationDate' => $fresh->layaway_expiration_date
                ? Carbon::parse($fresh->layaway_expiration_date)->format('d/m/Y')
                : null,
            'paymentMethod' => $this->formatPaymentBreakdown($breakdownPayments),
        ];
    }

    /**
     * Payload del ticket de abono general a la cuenta de un cliente.
     * $summary lo devuelve TransactionPaymentService::applyPaymentToCustomerBalance.
     */
    public function buildGeneralAbonoPayload(Customer $customer, array $summary): array
    {
        $subscription = $customer->branch?->subscription;

        $breakdown = collect($summary['breakdown'] ?? [])->map(function (array $row) {
            return [
                'folio' => $row['folio'],
                'abonado' => '$' . number_format((float) $row['abonado'], 2),
                'restante' => '$' . number_format((float) $row['restante'], 2),
                'liquidada' => (bool) $row['liquidada'],
            ];
        })->values()->all();

        $liquidatedFolios = collect($breakdown)
            ->where('liquidada', true)
            ->pluck('folio')
            ->values()
            ->all();

        return [
            'kind' => 'abono',
            'scope' => 'general',
            'businessName' => $subscription?->commercial_name ?: ($customer->branch?->name ?: 'Mi Negocio'),
            'date' => now()->format('d/m/Y - H:i'),
            'customer' => $customer->name,
            'totalAbonado' => '$' . number_format((float) ($summary['total_abonado'] ?? 0), 2) . ' MXN',
            'paymentMethod' => $summary['payment_method'] ?? '',
            'breakdown' => $breakdown,
            'liquidatedFolios' => $liquidatedFolios,
            'totalRemaining' => '$' . number_format((float) ($summary['total_remaining'] ?? 0), 2) . ' MXN',
            'nextExpiration' => $summary['next_expiration'] ?? null,
            'balanceCredit' => isset($summary['balance_credit']) && (float) $summary['balance_credit'] > 0.01
                ? '$' . number_format((float) $summary['balance_credit'], 2) . ' MXN'
                : null,
        ];
    }

    /**
     * Payload del ticket de pedido (POS) para WhatsApp.
     * El estado se deriva del estado actual del pedido y el método de pago
     * solo se muestra cuando el pedido está liquidado (completado/pagado).
     */
    public function buildOrderPayload(Transaction $transaction): array
    {
        $customer = $transaction->customer;
        $subscription = $transaction->branch?->subscription;
        $contactInfo = $transaction->contact_info;

        $items = $transaction->items->map(fn ($item) => [
            'cantidad' => (float) $item->quantity,
            'descripcion' => $item->description,
            'total' => '$' . number_format((float) $item->line_total, 2),
        ])->values();

        $total = (float) $transaction->subtotal - (float) $transaction->total_discount + (float) $transaction->total_tax + (float) ($transaction->shipping_cost ?? 0);
        $totalPaid = round((float) $transaction->payments->sum('amount'), 2);
        $remaining = max(0, round($total - $totalPaid, 2));

        $payload = [
            'kind' => 'order',
            'businessName' => $subscription?->commercial_name ?: ($transaction->branch?->name ?: 'Mi Negocio'),
            'date' => Carbon::parse($transaction->created_at)->format('d/m/Y - H:i'),
            'folio' => $transaction->folio,
            'statusLabel' => $this->orderStatusLabel($transaction->status),
            'customer' => $contactInfo['name'] ?? $customer?->name ?? 'Cliente',
            'items' => $items,
            'subtotal' => '$' . number_format((float) $transaction->subtotal, 2) . ' MXN',
            'shippingCost' => '$' . number_format((float) ($transaction->shipping_cost ?? 0), 2) . ' MXN',
            'total' => '$' . number_format($total, 2) . ' MXN',
            'totalPaid' => null,
            'remainingDue' => null,
            'paymentMethod' => null,
            'finalMessage' => '¡Gracias por tu pedido!',
        ];

        // Reenvío: si el pedido ya tiene pagos, el ticket general incluye los datos del pago.
        if ($totalPaid > 0.01) {
            $payments = $transaction->payments
                ->map(fn ($payment) => [
                    'method' => $payment->payment_method->value,
                    'amount' => (float) $payment->amount,
                ])
                ->values()
                ->all();

            $payload['totalPaid'] = '$' . number_format($totalPaid, 2) . ' MXN';
            $payload['remainingDue'] = '$' . number_format($remaining, 2) . ' MXN';
            $payload['paymentMethod'] = $this->formatPaymentBreakdown($payments);
        }

        return $payload;
    }

    /**
     * Payload del ticket de PAGO de un pedido (cuando se abona o liquida un pedido).
     * Estado: 'Completado' si quedó liquidado, 'Pendiente' si no terminó.
     * Se separa del ticket de abono (ventas normales).
     */
    public function buildOrderPaymentPayload(
        Transaction $transaction,
        float $previousDue,
        array $payments,
        float $usedBalance = 0.0
    ): array {
        $customer = $transaction->customer;
        $subscription = $transaction->branch?->subscription;
        $contactInfo = $transaction->contact_info;
        $fresh = $transaction->fresh();

        $total = (float) $fresh->total;
        $remaining = max(0, round($total - (float) $fresh->payments->sum('amount'), 2));
        $amountPaidNow = round((float) collect($payments)->sum('amount') + $usedBalance, 2);

        // El saldo a favor usado también se muestra como método de pago.
        $breakdownPayments = $payments;
        if ($usedBalance > 0) {
            $breakdownPayments[] = ['method' => 'saldo', 'amount' => $usedBalance];
        }

        $completed = $remaining <= 0.01;

        return [
            'kind' => 'order_payment',
            'businessName' => $subscription?->commercial_name ?: ($transaction->branch?->name ?: 'Mi Negocio'),
            'date' => now()->format('d/m/Y - H:i'),
            'folio' => $transaction->folio,
            'estado' => $completed ? 'Completado' : 'Pendiente',
            'customer' => $contactInfo['name'] ?? $customer?->name ?? 'Cliente',
            'total' => '$' . number_format($total, 2) . ' MXN',
            'previousDue' => '$' . number_format($previousDue, 2) . ' MXN',
            'abonado' => '$' . number_format($amountPaidNow, 2) . ' MXN',
            'paymentMethod' => $this->formatPaymentBreakdown($breakdownPayments),
            'remainingDue' => '$' . number_format($remaining, 2) . ' MXN',
            'finalMessage' => $completed
                ? '¡Gracias por tu pedido! Ya ha sido completado.'
                : '¡Gracias por tu pedido!',
        ];
    }

    /**
     * Payload del ticket de una VENTA (contado, crédito o apartado).
     *
     * Lo usan la web (PrintController::whatsappTicket) y la app móvil
     * (POST /print/whatsapp-ticket), así que ambos mandan el mismo texto.
     */
    public function buildSalePayload(Transaction $transaction): array
    {
        $transaction->loadMissing(['customer', 'items', 'payments', 'branch.subscription', 'customerBalanceMovements']);

        $subscription = $transaction->branch?->subscription;
        $customer = $transaction->customer;

        $items = $transaction->items->map(fn ($item) => [
            'cantidad' => (float) $item->quantity,
            'descripcion' => $item->description,
            'total' => '$' . number_format((float) $item->line_total, 2),
        ])->values();

        $total = (float) $transaction->subtotal - (float) $transaction->total_discount
            + (float) $transaction->total_tax + (float) ($transaction->shipping_cost ?? 0);
        $totalPaid = (float) $transaction->payments->sum('amount');
        $remainingDue = max(0, $total - $totalPaid);
        $saleType = $this->detectSaleType($transaction);

        return [
            'kind' => 'sale',
            'businessName' => $subscription?->commercial_name ?: ($transaction->branch?->name ?: 'Mi Negocio'),
            'title' => 'TICKET DE VENTA',
            'saleType' => $saleType,
            'saleTypeLabel' => $this->saleTypeLabel($saleType),
            'date' => Carbon::parse($transaction->created_at)->format('d/m/Y - H:i'),
            'folio' => $transaction->folio,
            'customer' => $customer?->name ?: 'Público en General',
            'items' => $items,
            'total' => '$' . number_format($total, 2) . ' MXN',
            'totalPaid' => '$' . number_format($totalPaid, 2) . ' MXN',
            'remainingDue' => $remainingDue > 0.01 ? '$' . number_format($remainingDue, 2) . ' MXN' : null,
            'expirationDate' => $transaction->layaway_expiration_date
                ? Carbon::parse($transaction->layaway_expiration_date)->format('d/m/Y')
                : null,
            'paymentMethod' => $this->formatPaymentMethod($transaction->payments, $total, $totalPaid),
            'address' => $customer ? implode(', ', array_filter((array) ($customer->address ?? []))) : '',
            'finalMessage' => '¡Gracias por tu compra!',
        ];
    }

    private function orderStatusLabel(TransactionStatus $status): string
    {
        return match ($status) {
            TransactionStatus::TO_DELIVER => 'Por entregar',
            TransactionStatus::IN_TRANSIT => 'En ruta',
            TransactionStatus::DELIVERED_UNPAID => 'Entregado por pagar',
            TransactionStatus::COMPLETED => 'Pagado',
            TransactionStatus::CANCELLED => 'Cancelado',
            TransactionStatus::PENDING => 'Pendiente',
            TransactionStatus::REFUNDED => 'Reembolsado',
            default => ucfirst(str_replace('_', ' ', $status->value)),
        };
    }

    /**
     * Detecta el tipo de venta (contado, crédito o apartado) combinando el
     * estatus actual de la transacción con sus movimientos de saldo.
     */
    private function detectSaleType(Transaction $transaction): string
    {
        $status = $transaction->status;

        if ($status === TransactionStatus::ON_LAYAWAY) {
            return 'apartado';
        }

        if ($status === TransactionStatus::PENDING) {
            return 'credito';
        }

        // COMPLETED: distinguir por los movimientos de saldo generados al vender.
        $movementTypes = $transaction->customerBalanceMovements
            ->map(fn ($movement) => $movement->type->value);

        if ($movementTypes->contains(CustomerBalanceMovementType::LAYAWAY_DEBT->value)) {
            return 'apartado';
        }

        if ($movementTypes->contains(CustomerBalanceMovementType::CREDIT_SALE->value)) {
            return 'credito';
        }

        // Crédito liquidado sin movimiento de deuda (se pagó con saldo a favor).
        if ($transaction->layaway_expiration_date) {
            return 'credito';
        }

        return 'contado';
    }

    private function saleTypeLabel(string $saleType): string
    {
        return match ($saleType) {
            'credito' => 'A crédito',
            'apartado' => 'Apartado',
            default => 'Pago al contado',
        };
    }

    /**
     * Formatea el método de pago para el ticket de WhatsApp.
     * Para efectivo puro incluye el monto pagado y el cambio calculado;
     * para pagos mixtos muestra cada método con su monto.
     */
    private function formatPaymentMethod($payments, float $total, float $totalPaid): string
    {
        if ($payments->isEmpty()) {
            return '';
        }

        $groups = $payments->groupBy(fn ($payment) => $payment->payment_method->value);

        // Efectivo puro: conservar el formato actual con cambio.
        if ($groups->count() === 1 && $groups->has('efectivo')) {
            $change = max(0, $totalPaid - $total);
            return 'Efectivo (Pagado: $' . number_format($totalPaid, 2) . ' | Cambio: $' . number_format($change, 2) . ')';
        }

        return $groups->map(function ($group, $method) {
            $label = match ($method) {
                'efectivo' => 'Efectivo',
                'tarjeta' => 'Tarjeta',
                'transferencia' => 'Transferencia',
                'saldo' => 'Saldo a favor',
                'intercambio' => 'Intercambio',
                default => ucfirst($method),
            };

            return $label . ': $' . number_format((float) $group->sum('amount'), 2);
        })->values()->implode(', ');
    }
}
