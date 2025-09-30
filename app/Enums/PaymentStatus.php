<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PROCESSING = 'procesando';
    case COMPLETED = 'completado';
    case FAILED = 'fallido';
}