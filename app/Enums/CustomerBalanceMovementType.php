<?php

namespace App\Enums;

enum CustomerBalanceMovementType: string
{
    // Tipos existentes
    case CREDIT_SALE = 'venta_a_credito';        // Cargo al cliente por una venta a crédito
    case PAYMENT = 'abono';                      // Abono realizado por el cliente a su saldo
    case CREDIT_PURCHASE = 'compra_de_credito';    // Compra de saldo a favor (si aplica)
    case LAYAWAY_DEBT = 'deuda_por_apartado';   // Deuda generada por un apartado
    case CREDIT_USAGE = 'uso_de_credito';        // Uso de saldo a favor para pagar una venta
    case BALANCE_REFUND = 'devolucion_a_balance';  // Devolución de dinero que aumenta el saldo a favor
    case MANUAL_ADJUSTMENT = 'ajuste_manual';    // Ajuste manual por el administrador

    // --- NUEVOS TIPOS AÑADIDOS ---
    /**
     * Movimiento de crédito (positivo para el cliente) generado
     * al cancelar una venta que originalmente fue a crédito.
     * Revierte el cargo de CREDIT_SALE.
     */
    case CANCELLATION_CREDIT = 'credito_por_cancelacion';

    /**
     * Movimiento de crédito (positivo para el cliente) generado
     * al reembolsar una venta que originalmente fue a crédito.
     * Puede ser similar a CANCELLATION_CREDIT o tener lógica distinta.
     */
    case REFUND_CREDIT = 'credito_por_reembolso';
}