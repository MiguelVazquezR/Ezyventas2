<?php

namespace App\Enums;

enum StampPaymentMethod: string
{
    case MERCADOPAGO = 'mercadopago';
    case BANK_TRANSFER = 'bank_transfer';
    case MANUAL_ADJUSTMENT = 'manual_adjustment';
}
