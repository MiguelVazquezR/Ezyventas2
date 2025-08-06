<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH = 'efectivo';
    case CARD = 'tarjeta';
    case TRANSFER = 'transferencia';
}