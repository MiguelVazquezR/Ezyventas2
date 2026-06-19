<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    // --- Subscription-payment invoice lifecycle (existing) ---
    case NOT_REQUESTED = 'no_solicitada';
    case REQUESTED = 'solicitada';
    case GENERATED = 'generada';

    // --- CFDI 4.0 lifecycle ---
    case DRAFT = 'borrador';
    case PENDING = 'pendiente';
    case CERTIFIED = 'certificada';
    case CANCELED = 'cancelada';
}
