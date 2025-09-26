<?php

namespace App\Enums;

enum AffiliatePayoutStatus: string
{
    case PENDING = 'pendiente';
    case COMPLETED = 'completado';
    case FAILED = 'fallido';
}