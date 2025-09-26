<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case PAID = 'pagado';
    case PENDING = 'pendiente';
}