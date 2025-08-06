<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case COMPLETED = 'completado';
    case FAILED = 'fallido';
}