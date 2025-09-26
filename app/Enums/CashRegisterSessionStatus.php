<?php

namespace App\Enums;

enum CashRegisterSessionStatus: string
{
    case OPEN = 'abierta';
    case CLOSED = 'cerrada';
}