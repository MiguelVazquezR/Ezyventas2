<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case COMPLETED = 'completado';
    case PENDING = 'pendiente';
    case CANCELLED = 'cancelado';
    case REFUNDED = 'reembolsado';
}