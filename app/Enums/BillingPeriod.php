<?php

namespace App\Enums;

enum BillingPeriod: string
{
    case MONTHLY = 'mensual';
    case ANNUALLY = 'anual';
}
