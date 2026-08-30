<?php

namespace App\Services;

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
}
