<?php

namespace App\Enums;

enum SessionCashMovementType: string
{
    case INFLOW = 'ingreso';
    case OUTFLOW = 'egreso';
}