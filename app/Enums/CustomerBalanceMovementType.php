<?php

namespace App\Enums;

enum CustomerBalanceMovementType: string
{
    case CREDIT_SALE = 'venta_a_credito';
    case PAYMENT = 'abono';
    case CREDIT_PURCHASE = 'compra_de_credito';
    case CREDIT_USAGE = 'uso_de_credito';
    case BALANCE_REFUND = 'devolucion_a_balance';
    case MANUAL_ADJUSTMENT = 'ajuste_manual';
}