<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case DRAFT = 'borrador';
    case SENT = 'enviado';
    case AUTHORIZED = 'autorizada';
    case REJECTED = 'rechazada';
    case SALE_GENERATED = 'venta_generada';
    case EXPIRED = 'expirada';
}