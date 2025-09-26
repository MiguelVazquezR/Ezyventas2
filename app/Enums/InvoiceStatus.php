<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case NOT_REQUESTED = 'no_solicitada';
    case REQUESTED = 'solicitada';
    case GENERATED = 'generada';
}
