<?php

namespace App\Enums;

enum ReferralUsageStatus: string
{
    case PENDING_PAYMENT = 'pago_pendiente';
    case PAID = 'pagado';
    case CANCELLED = 'cancelado';
}